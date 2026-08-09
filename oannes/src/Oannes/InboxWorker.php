<?php

namespace Oannes;

use RuntimeException;

final class InboxWorker
{
    public function __construct(
        private readonly FileStore $store,
        private readonly FileQueue $queue,
    ) {
    }

    public function run(int $limit = 25): array
    {
        $stats = [
            'checked' => 0,
            'processed' => 0,
            'failed' => 0,
            'dead' => 0,
            'skipped' => 0,
            'follows' => 0,
            'creates' => 0,
            'other' => 0,
        ];

        $handled = 0;
        foreach ($this->queue->due(100000) as $job) {
            $stats['checked']++;

            if (($job['type'] ?? null) !== 'inbox') {
                $stats['skipped']++;
                continue;
            }

            $handled++;
            try {
                $kind = $this->process($job);
                $stats['processed']++;
                $stats[$kind]++;
                $this->queue->complete($job);
            } catch (\Throwable $e) {
                $attempts = ((int)($job['attempts'] ?? 0)) + 1;

                if ($attempts >= 3) {
                    $this->queue->dead($job, $e->getMessage());
                    $stats['dead']++;
                } else {
                    $this->queue->fail($job, $e->getMessage(), 60 * $attempts);
                    $stats['failed']++;
                }
            }

            if ($handled >= $limit) {
                break;
            }
        }

        return $stats;
    }

    private function process(array $job): string
    {
        $payload = $job['payload'] ?? null;
        $recordPath = is_array($payload) ? ($payload['record'] ?? null) : null;

        if (!is_string($recordPath) || !str_starts_with($recordPath, $this->store->dataDir() . '/inbox/accepted/')) {
            throw new RuntimeException('Inbox job points outside accepted inbox storage');
        }

        if (!is_file($recordPath)) {
            throw new RuntimeException('Inbox record does not exist');
        }

        $record = $this->store->readJson($recordPath);
        $activity = $record['activity'] ?? null;

        if (!is_array($activity)) {
            throw new RuntimeException('Inbox record has no activity');
        }

        $localUid = is_string($record['local_uid'] ?? null) ? $record['local_uid'] : '';
        $actorId = ActivityPub::attributedTo($activity) ?? '';
        if ($localUid !== '' && $actorId !== '' && (new SocialRelationService($this->store))->isBlocked($localUid, $actorId)) {
            return 'skipped';
        }

        $type = ActivityPub::objectType($activity);

        if ($type === 'Follow') {
            if ($this->acceptExistingFollower($localUid, $actorId, $activity)) {
                return 'follows';
            }

            $this->writeReview('follows', $record, $activity);
            return 'follows';
        }

        if ($type === 'Create') {
            if ($this->acceptCreateFromFollowed($localUid, $actorId, $activity)) {
                return 'creates';
            }

            $this->writeReview('creates', $record, $activity);
            return 'creates';
        }

        $this->writeReview('other', $record, $activity);
        return 'other';
    }

    private function acceptExistingFollower(string $localUid, string $actorId, array $activity): bool
    {
        if ($localUid === '' || $actorId === '' || !(new SocialGraph($this->store))->isFollower($localUid, $actorId)) {
            return false;
        }

        $actor = (new ActorRepository($this->store))->findById($actorId);
        if ($actor === null) {
            return false;
        }

        $inbox = (new SocialGraph($this->store))->inboxForActor($actor);
        if ($inbox === null) {
            return true;
        }

        $localActor = $this->followObjectActor($activity);
        if ($localActor === '') {
            return true;
        }

        $this->queue->enqueue('deliver', [
            'actor' => $localActor,
            'inbox' => $inbox,
            'activity' => $this->followResponseActivity($localActor, $activity),
        ]);

        return true;
    }

    private function followObjectActor(array $follow): string
    {
        $object = $follow['object'] ?? null;

        if (is_string($object) && $object !== '') {
            return $object;
        }

        if (is_array($object)) {
            return ActivityPub::objectId($object) ?? '';
        }

        return '';
    }

    private function followResponseActivity(string $localActor, array $follow): array
    {
        $activityId = ActivityPub::objectId($follow) ?? Id::digest(Json::encode($follow));

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $localActor . '/activities/accept/' . Id::digest($activityId),
            'type' => 'Accept',
            'actor' => $localActor,
            'object' => $follow,
            'published' => gmdate('c'),
            'to' => [
                ActivityPub::attributedTo($follow) ?? '',
            ],
        ];
    }

    private function acceptCreateFromFollowed(string $localUid, string $actorId, array $activity): bool
    {
        if ($localUid === '' || $actorId === '' || !(new SocialGraph($this->store))->isFollowing($localUid, $actorId)) {
            return false;
        }

        $object = $activity['object'] ?? null;
        if (!is_array($object)) {
            return false;
        }

        $objectId = ActivityPub::objectId($object);
        if ($objectId === null || !in_array(ActivityPub::objectType($object), ['Note', 'Article', 'Page', 'Question'], true)) {
            return false;
        }

        $objectActor = ActivityPub::attributedTo($object);
        if ($objectActor !== null && $objectActor !== $actorId) {
            return false;
        }

        $repo = new ObjectRepository($this->store);
        $existing = $repo->findByIdOrAlias($objectId);
        $receivedBy = is_array($existing['_oannes_inbox_uids'] ?? null) ? $existing['_oannes_inbox_uids'] : [];
        $receivedBy[] = $localUid;
        $object['_oannes_inbox_uids'] = array_values(array_unique(array_filter($receivedBy, 'is_string')));

        $this->store->writeObject($object);
        (new IndexBuilder($this->store))->rebuild();

        return true;
    }

    private function writeReview(string $kind, array $record, array $activity): void
    {
        $localUid = $record['local_uid'] ?? 'unknown';
        $activityId = ActivityPub::objectId($activity) ?? ($record['id'] ?? bin2hex(random_bytes(8)));
        $path = $this->store->dataDir()
            . '/moderation/inbox/'
            . rawurlencode((string)$localUid)
            . '/'
            . $kind
            . '/'
            . Id::digest((string)$activityId)
            . '.json';

        $this->store->writeJson($path, [
            'status' => 'pending',
            'kind' => $kind,
            'created_at' => gmdate('c'),
            'record' => $record,
        ]);
    }
}
