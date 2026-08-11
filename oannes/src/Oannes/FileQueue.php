<?php

namespace Oannes;

final class FileQueue
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function enqueue(string $type, array $payload, ?string $notBefore = null): string
    {
        $id = $this->stableId($type, $payload) ?? gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));

        foreach (['pending', 'processing', 'done'] as $status) {
            if (is_file($this->path($status, $id))) {
                return $id;
            }
        }

        $job = [
            'id' => $id,
            'type' => $type,
            'status' => 'pending',
            'attempts' => 0,
            'created_at' => gmdate('c'),
            'not_before' => $notBefore ?? gmdate('c'),
            'last_error' => null,
            'payload' => $payload,
        ];

        $this->store->writeJson($this->path('pending', $id), $job);
        return $id;
    }

    public function list(string $status = 'pending', int $limit = 100): array
    {
        $jobs = [];
        $dir = $this->store->dataDir() . '/queue/' . $status;

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $jobs[] = $this->store->readJson($file);
        }

        usort($jobs, static fn (array $a, array $b): int => strcmp(
            (string)($a['not_before'] ?? $a['created_at'] ?? ''),
            (string)($b['not_before'] ?? $b['created_at'] ?? '')
        ));

        return array_slice($jobs, 0, $limit);
    }

    public function due(int $limit = 25): array
    {
        $this->recoverStaleProcessing();

        $now = gmdate('c');
        $due = [];

        foreach ($this->list('pending', 100000) as $job) {
            if ((string)($job['not_before'] ?? '') <= $now) {
                $due[] = $job;
            }

            if (count($due) >= $limit) {
                break;
            }
        }

        return $due;
    }

    public function claim(array $job): ?array
    {
        $id = (string)($job['id'] ?? '');

        if ($id === '') {
            return null;
        }

        $pending = $this->path('pending', $id);
        $processing = $this->path('processing', $id);

        if (!is_file($pending)) {
            return null;
        }

        $dir = dirname($processing);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        if (!@rename($pending, $processing)) {
            return null;
        }

        $claimed = is_file($processing) ? $this->store->readJson($processing) : $job;
        $claimed['status'] = 'processing';
        $claimed['claimed_at'] = gmdate('c');
        $this->store->writeJson($processing, $claimed);

        return $claimed;
    }

    public function fail(array $job, string $error, int $retrySeconds = 300): void
    {
        $id = (string)$job['id'];
        $this->remove('processing', $id);
        $this->remove('pending', $id);

        $job['status'] = 'pending';
        $job['attempts'] = ((int)($job['attempts'] ?? 0)) + 1;
        $job['last_error'] = $error;
        $job['not_before'] = gmdate('c', time() + $retrySeconds);

        $this->store->writeJson($this->path('pending', $id), $job);
    }

    public function dead(array $job, string $error): void
    {
        $id = (string)$job['id'];
        $this->remove('processing', $id);
        $this->remove('pending', $id);

        $job['status'] = 'dead';
        $job['last_error'] = $error;
        $job['dead_at'] = gmdate('c');

        $this->store->writeJson($this->path('dead', $id), $job);
    }

    public function complete(array $job): void
    {
        $id = (string)$job['id'];
        $this->remove('processing', $id);
        $this->remove('pending', $id);

        $job['status'] = 'done';
        $job['completed_at'] = gmdate('c');

        $this->store->writeJson($this->path('done', $id), $job);
    }

    private function path(string $status, string $id): string
    {
        return $this->store->dataDir() . '/queue/' . $status . '/' . $id . '.json';
    }

    private function stableId(string $type, array $payload): ?string
    {
        if ($type !== 'deliver') {
            return null;
        }

        $inbox = $payload['inbox'] ?? null;
        $activity = $payload['activity'] ?? null;
        $activityId = is_array($activity) ? ($activity['id'] ?? null) : null;

        if (!is_string($inbox) || $inbox === '' || !is_string($activityId) || $activityId === '') {
            return null;
        }

        return 'deliver-' . Id::digest($inbox . "\n" . $activityId);
    }

    private function recoverStaleProcessing(int $olderThanSeconds = 1800): void
    {
        $dir = $this->store->dataDir() . '/queue/processing';
        $threshold = time() - $olderThanSeconds;

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            if (!is_file($file) || filemtime($file) > $threshold) {
                continue;
            }

            $id = basename($file, '.json');
            @rename($file, $this->path('pending', $id));
        }
    }

    private function remove(string $status, string $id): void
    {
        $path = $this->path($status, $id);

        if (is_file($path)) {
            unlink($path);
        }
    }
}
