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

        foreach ($this->queue->due($limit) as $job) {
            $stats['checked']++;

            if (($job['type'] ?? null) !== 'inbox') {
                $stats['skipped']++;
                continue;
            }

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
            $this->writeReview('follows', $record, $activity);
            return 'follows';
        }

        if ($type === 'Create') {
            $this->writeReview('creates', $record, $activity);
            return 'creates';
        }

        $this->writeReview('other', $record, $activity);
        return 'other';
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
