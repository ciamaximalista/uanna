<?php

namespace Oannes;

final class OpportunisticMaintenance
{
    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function run(string $scope = 'web'): array
    {
        $settings = (new InstanceSettings($this->store, $this->config))->all();
        if (($settings['update_mode'] ?? 'activity') === 'cron') {
            return ['ran' => false, 'reason' => 'cron_mode'];
        }

        if (!(bool)($this->config['opportunistic_workers_enabled'] ?? true)) {
            return ['ran' => false, 'reason' => 'disabled'];
        }

        $cooldown = max(0, (int)($this->config['opportunistic_workers_cooldown_seconds'] ?? 20));
        $statePath = $this->store->dataDir() . '/maintenance/' . $scope . '.json';

        if ($cooldown > 0 && is_file($statePath)) {
            $state = $this->readJson($statePath);
            $lastRun = strtotime((string)($state['finished_at'] ?? $state['started_at'] ?? '')) ?: 0;
            if ($lastRun > 0 && time() - $lastRun < $cooldown) {
                return ['ran' => false, 'reason' => 'cooldown'];
            }
        }

        $lockPath = $this->store->dataDir() . '/maintenance/' . $scope . '.lock';
        $lockDir = dirname($lockPath);
        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return ['ran' => false, 'reason' => 'lock_dir'];
        }

        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            return ['ran' => false, 'reason' => 'lock_open'];
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return ['ran' => false, 'reason' => 'locked'];
        }

        $started = microtime(true);
        $stats = [
            'ran' => true,
            'started_at' => gmdate('c'),
            'inbox' => null,
            'delivery' => null,
        ];

        try {
            $inboxLimit = max(0, min(50, (int)($this->config['opportunistic_inbox_limit'] ?? 5)));
            if ($inboxLimit > 0 && (bool)($this->config['inbox_enabled'] ?? false)) {
                $stats['inbox'] = (new InboxWorker($this->store, new FileQueue($this->store), $this->config))->run($inboxLimit);
            }

            $deliveryLimit = max(0, min(25, (int)($this->config['opportunistic_delivery_limit'] ?? 2)));
            if ($deliveryLimit > 0 && (bool)($this->config['delivery_enabled'] ?? false)) {
                $stats['delivery'] = (new DeliveryWorker(
                    $this->store,
                    new FileQueue($this->store),
                    new KeyStore($this->store),
                    $this->config,
                ))->run($deliveryLimit, false);
            }
        } catch (\Throwable $e) {
            $stats['error'] = $e->getMessage();
        } finally {
            $stats['finished_at'] = gmdate('c');
            $stats['elapsed_ms'] = (int)round((microtime(true) - $started) * 1000);
            $this->store->writeJson($statePath, $stats);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $stats;
    }

    private function readJson(string $path): array
    {
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
