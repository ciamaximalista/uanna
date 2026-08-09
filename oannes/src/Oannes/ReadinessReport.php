<?php

namespace Oannes;

final class ReadinessReport
{
    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function generate(int $simulationIterations = 10): array
    {
        $threadValidation = (new ThreadValidator($this->store))->validate();
        $simulation = (new SimulationRunner())->run($simulationIterations);
        $queue = new FileQueue($this->store);
        $pending = $queue->list('pending', 100000);
        $dead = $queue->list('dead', 100000);
        $manifestPath = $this->store->dataDir() . '/indexes/manifest.json';
        $manifest = is_file($manifestPath) ? $this->store->readJson($manifestPath) : [];
        $blockers = [];

        if (($threadValidation['ok'] ?? false) !== true) {
            $blockers[] = 'thread_validation_failed';
        }

        if (($simulation['ok'] ?? false) !== true) {
            $blockers[] = 'simulation_failed';
        }

        if ($dead !== []) {
            $blockers[] = 'dead_queue_jobs_present';
        }

        if ((bool)($this->config['delivery_enabled'] ?? false)) {
            $blockers[] = 'delivery_enabled_before_cutover';
        }

        if ((bool)($this->config['inbox_enabled'] ?? false)) {
            $blockers[] = 'inbox_enabled_before_cutover';
        }

        return [
            'ready_for_cutover' => $blockers === [],
            'blockers' => $blockers,
            'configuration' => [
                'storage' => 'files-json-xml',
                'delivery_enabled' => (bool)($this->config['delivery_enabled'] ?? false),
                'inbox_enabled' => (bool)($this->config['inbox_enabled'] ?? false),
                'community_mode' => $this->config['community_mode'] ?? null,
            ],
            'indexes' => $manifest['counts'] ?? null,
            'queues' => [
                'pending' => count($pending),
                'dead' => count($dead),
            ],
            'thread_validation' => $threadValidation,
            'simulation' => [
                'iterations' => $simulation['iterations'] ?? $simulationIterations,
                'checks' => $simulation['checks'] ?? null,
                'failed' => $simulation['failed'] ?? [],
            ],
        ];
    }
}
