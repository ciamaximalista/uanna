<?php

namespace Oannes;

use RuntimeException;

final class InboxWorker
{
    public function __construct(
        private readonly FileStore $store,
        private readonly FileQueue $queue,
        private readonly array $config = [],
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
            if ($this->acceptCreateFromUnblockedConversation($localUid, $actorId, $activity)) {
                return 'creates';
            }

            if ($this->acceptCreateFromFollowed($localUid, $actorId, $activity)) {
                return 'creates';
            }

            $this->writeReview('creates', $record, $activity);
            return 'creates';
        }

        if ($type === 'Update' && $this->acceptUpdate($localUid, $actorId, $activity)) {
            return 'other';
        }

        if ($type === 'Announce' && $this->acceptReplyMentionAnnounce($localUid, $actorId, $activity)) {
            return 'other';
        }

        if (in_array($type, ['Like', 'Announce'], true) && $this->acceptInteraction($localUid, $activity)) {
            return 'other';
        }

        if ($type === 'Undo' && $this->acceptUndo($localUid, $activity)) {
            return 'other';
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
        $this->notifyAcceptedCreate($localUid, $activity, $object);

        return true;
    }

    private function acceptCreateFromUnblockedConversation(string $localUid, string $actorId, array $activity): bool
    {
        if ($localUid === '' || $actorId === '') {
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

        if (ActivityPub::inReplyTo($object) === null) {
            return false;
        }

        $this->storeAcceptedObject($localUid, $object);
        $this->bumpLocalReplyThread($localUid, $object);
        $this->notifyAcceptedCreate($localUid, $activity, $object);

        return true;
    }

    private function bumpLocalReplyThread(string $localUid, array $object): void
    {
        $parentId = ActivityPub::inReplyTo($object);
        if ($parentId === null) {
            return;
        }

        $repo = new ObjectRepository($this->store);
        $parent = $repo->findByIdOrAlias($parentId);
        if ($parent === null || !$this->isLocalUserObject($localUid, $parent)) {
            return;
        }

        $activityAt = ActivityPub::published($object);
        if ($activityAt === '') {
            $activityAt = gmdate('c');
        }

        $this->bumpObjectThreadActivity($parent, $activityAt);
        $root = $this->threadRoot($repo, $parent);
        $rootId = ActivityPub::objectId($root);
        $parentObjectId = ActivityPub::objectId($parent);
        if ($rootId !== null && $rootId !== $parentObjectId) {
            $this->bumpObjectThreadActivity($root, $activityAt);
        }

        (new IndexBuilder($this->store))->rebuild();
    }

    private function isLocalUserObject(string $localUid, array $object): bool
    {
        if ($this->config === []) {
            return false;
        }

        $actor = ActivityPub::attributedTo($object);
        if ($actor === null) {
            return false;
        }

        $users = new LocalUsers($this->store, $this->config);
        return in_array($actor, array_merge([$users->actorId($localUid)], $users->legacyActorIds($localUid)), true);
    }

    private function threadRoot(ObjectRepository $repo, array $object): array
    {
        $root = $object;
        $seen = [];

        for ($depth = 0; $depth < 8; $depth++) {
            $parentId = ActivityPub::inReplyTo($root);
            if ($parentId === null || isset($seen[$parentId])) {
                break;
            }

            $seen[$parentId] = true;
            $parent = $repo->findByIdOrAlias($parentId);
            if ($parent === null) {
                break;
            }

            $root = $parent;
        }

        return $root;
    }

    private function bumpObjectThreadActivity(array $object, string $activityAt): void
    {
        $current = $object['_oannes_thread_activity_at'] ?? null;
        if (is_string($current) && $current >= $activityAt) {
            return;
        }

        $object['_oannes_thread_activity_at'] = $activityAt;
        $this->store->writeObject($object);
    }

    private function storeAcceptedObject(string $localUid, array $object): void
    {
        $objectId = ActivityPub::objectId($object);
        $existing = $objectId !== null ? (new ObjectRepository($this->store))->findByIdOrAlias($objectId) : null;
        $receivedBy = is_array($existing['_oannes_inbox_uids'] ?? null) ? $existing['_oannes_inbox_uids'] : [];
        $receivedBy[] = $localUid;
        $object['_oannes_inbox_uids'] = array_values(array_unique(array_filter($receivedBy, 'is_string')));

        $this->store->writeObject($object);
        (new IndexBuilder($this->store))->rebuild();
    }

    private function acceptInteraction(string $localUid, array $activity): bool
    {
        if ($localUid === '') {
            return false;
        }

        $type = ActivityPub::objectType($activity);
        $actor = ActivityPub::attributedTo($activity);
        $objectValue = $activity['object'] ?? null;
        $object = is_string($objectValue) ? $objectValue : (is_array($objectValue) ? ActivityPub::objectId($objectValue) : null);

        if (!in_array($type, ['Like', 'Announce'], true) || $actor === null || !is_string($object) || $object === '') {
            return false;
        }

        if ($type === 'Announce' && (new SocialGraph($this->store))->isFollowing($localUid, $actor)) {
            $this->cacheAnnouncedObject($localUid, $objectValue, $object);
        }

        $activity['object'] = $object;
        $this->store->writeJson($this->remoteInteractionPath($localUid, $actor, $type, $object), $activity);
        $this->writeNotification($localUid, $type, $actor, $object, ActivityPub::published($activity));

        return true;
    }

    private function cacheAnnouncedObject(string $localUid, mixed $objectValue, string $objectId): void
    {
        $object = is_array($objectValue) ? $objectValue : null;

        if ($object === null) {
            $existing = (new ObjectRepository($this->store))->findByIdOrAlias($objectId);
            if ($existing !== null) {
                $this->storeAcceptedObject($localUid, $existing);
                return;
            }

            $object = $this->fetchRemoteObject($objectId);
        }

        if ($object === null || !in_array(ActivityPub::objectType($object), ['Note', 'Article', 'Page', 'Question'], true)) {
            return;
        }

        if (ActivityPub::objectId($object) === null) {
            return;
        }

        $this->storeAcceptedObject($localUid, $object);
        $this->cacheObjectActor($object);
    }

    private function fetchRemoteObject(string $url): ?array
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/activity+json, application/ld+json; profile=\"https://www.w3.org/ns/activitystreams\", application/json\r\nUser-Agent: Uanna/0.1\r\n",
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false || $body === '') {
            return null;
        }

        try {
            $object = Json::decode($body, $url);
        } catch (\Throwable) {
            return null;
        }

        return $object;
    }

    private function cacheObjectActor(array $object): void
    {
        $actorId = ActivityPub::attributedTo($object);
        if ($actorId === null || !str_starts_with($actorId, 'https://')) {
            return;
        }

        if ((new ActorRepository($this->store))->findById($actorId) !== null) {
            return;
        }

        $actor = $this->fetchRemoteObject($actorId);
        if ($actor !== null && ActivityPub::isActor($actor)) {
            $this->store->writeActor($actor);
        }
    }

    private function acceptUpdate(string $localUid, string $actorId, array $activity): bool
    {
        if ($localUid === '' || $actorId === '') {
            return false;
        }

        $object = $activity['object'] ?? null;
        if (!is_array($object)) {
            return false;
        }

        $objectId = ActivityPub::objectId($object);
        if ($objectId === null) {
            return false;
        }

        if (ActivityPub::isActor($object)) {
            if ($objectId !== $actorId) {
                return false;
            }

            $existing = (new ActorRepository($this->store))->findById($actorId);
            $actor = is_array($existing) ? array_replace($existing, $object) : $object;
            $this->store->writeActor($actor);
            (new SocialGraph($this->store))->updateActorCopies($actor);
            return true;
        }

        if (!in_array(ActivityPub::objectType($object), ['Note', 'Article', 'Page', 'Question'], true)) {
            return false;
        }

        $objectActor = ActivityPub::attributedTo($object);
        if ($objectActor !== null && $objectActor !== $actorId) {
            return false;
        }

        $this->storeAcceptedObject($localUid, $object);
        $url = $this->objectUrl($object);
        if ($url !== '') {
            $this->store->writeJson($this->actorStatePath($actorId, 'last-update'), [
                'actor' => $actorId,
                'object' => $objectId,
                'url' => $url,
                'updated_at' => gmdate('c'),
            ]);
        }

        return true;
    }

    private function acceptReplyMentionAnnounce(string $localUid, string $actorId, array $activity): bool
    {
        if ($localUid === '' || $actorId === '' || !$this->isReplyMentionAnnounce($activity)) {
            return false;
        }

        $target = $activity['object'] ?? null;
        if (!is_string($target) || $target === '') {
            return false;
        }

        $source = $this->lastActorUpdateUrl($actorId) ?: $this->recentActorUpdateUrl($localUid, $actorId) ?: $actorId;
        $this->writeNotification($localUid, 'Webmention', $source, $target, ActivityPub::published($activity));

        return true;
    }

    private function isReplyMentionAnnounce(array $activity): bool
    {
        $id = ActivityPub::objectId($activity) ?? '';
        $path = parse_url($id, PHP_URL_PATH);

        return is_string($path) && str_contains($path, '/reply-announces/');
    }

    private function acceptUndo(string $localUid, array $activity): bool
    {
        if ($localUid === '') {
            return false;
        }

        $object = $activity['object'] ?? null;
        if (is_array($object)) {
            $type = ActivityPub::objectType($object);
            $actor = ActivityPub::attributedTo($object);
            $target = $object['object'] ?? null;

            if (in_array($type, ['Like', 'Announce'], true) && $actor !== null && is_string($target) && $target !== '') {
                $path = $this->remoteInteractionPath($localUid, $actor, $type, $target);
                if (is_file($path)) {
                    unlink($path);
                }

                return true;
            }
        }

        $undoneId = is_string($object) ? $object : ActivityPub::objectId($activity);
        if ($undoneId === null || $undoneId === '') {
            return false;
        }

        foreach (glob($this->store->dataDir() . '/interactions/remote/' . rawurlencode($localUid) . '/*.json') ?: [] as $file) {
            $stored = $this->readJsonFile($file);
            if (ActivityPub::objectId($stored) === $undoneId) {
                unlink($file);
                return true;
            }
        }

        return true;
    }

    private function notifyAcceptedCreate(string $localUid, array $activity, array $object): void
    {
        if (ActivityPub::inReplyTo($object) === null) {
            return;
        }

        $actor = ActivityPub::attributedTo($activity) ?? ActivityPub::attributedTo($object) ?? '';
        $objectId = ActivityPub::objectId($object) ?? '';
        if ($actor === '' || $objectId === '') {
            return;
        }

        $this->writeNotification($localUid, 'Create', $actor, $objectId, ActivityPub::published($object));
    }

    private function writeNotification(string $localUid, string $type, string $actor, string $objid, string $date = ''): void
    {
        $root = $this->notificationRoot();
        $id = Id::digest($type . ':' . $actor . ':' . $objid);

        $this->store->writeJson($root . '/users/' . rawurlencode($localUid) . '/notify/' . $id . '.json', [
            'type' => $type,
            'utype' => $type,
            'actor' => $actor,
            'objid' => $objid,
            'date' => $date !== '' ? $date : gmdate('c'),
        ]);
    }

    private function notificationRoot(): string
    {
        return $this->store->dataDir();
    }

    private function remoteInteractionPath(string $localUid, string $actor, string $type, string $object): string
    {
        return $this->store->dataDir()
            . '/interactions/remote/'
            . rawurlencode($localUid)
            . '/'
            . Id::digest($actor . ':' . $type . ':' . $object)
            . '.json';
    }

    private function actorStatePath(string $actorId, string $name): string
    {
        return $this->store->dataDir() . '/state/actors/' . Id::digest($actorId) . '/' . $name . '.json';
    }

    private function lastActorUpdateUrl(string $actorId): string
    {
        $path = $this->actorStatePath($actorId, 'last-update');
        if (!is_file($path)) {
            return '';
        }

        $state = $this->readJsonFile($path);
        $url = $state['url'] ?? null;

        return is_string($url) ? $url : '';
    }

    private function recentActorUpdateUrl(string $localUid, string $actorId): string
    {
        $files = glob($this->store->dataDir() . '/inbox/accepted/' . rawurlencode($localUid) . '/*.json') ?: [];
        rsort($files);

        foreach ($files as $file) {
            $record = $this->readJsonFile($file);
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            if (($activity['type'] ?? null) !== 'Update' || ActivityPub::attributedTo($activity) !== $actorId) {
                continue;
            }

            $object = is_array($activity['object'] ?? null) ? $activity['object'] : [];
            $url = $this->objectUrl($object);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function objectUrl(array $object): string
    {
        $url = $object['url'] ?? null;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        if (is_array($url)) {
            foreach ($url as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }

                if (is_array($item) && is_string($item['href'] ?? null) && $item['href'] !== '') {
                    return $item['href'];
                }
            }
        }

        return ActivityPub::objectId($object) ?? '';
    }

    private function readJsonFile(string $path): array
    {
        try {
            $json = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
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
