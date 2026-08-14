<?php

namespace Oannes;

final class ObjectRepository
{
    private ?array $objects = null;
    private ?array $aliases = null;
    private ?array $children = null;
    private ?array $byActor = null;

    public function __construct(private readonly FileStore $store)
    {
    }

    public function findByIdOrAlias(string $id): ?array
    {
        $canonical = $this->aliases()[$id] ?? $id;
        $meta = $this->objects()[$canonical] ?? null;

        if (!is_array($meta) || !isset($meta['path']) || !is_string($meta['path'])) {
            return null;
        }

        $path = $this->store->dataDir() . '/' . $meta['path'];
        return is_file($path) ? $this->store->readJson($path) : null;
    }

    public function childrenOf(string $id): array
    {
        $canonical = $this->aliases()[$id] ?? $id;
        $ids = $this->children()[$canonical] ?? [];
        $objects = [];

        foreach ($ids as $childId) {
            if (is_string($childId)) {
                $child = $this->findByIdOrAlias($childId);
                if ($child !== null) {
                    $objects[] = $child;
                }
            }
        }

        return $objects;
    }

    public function recent(int $limit = 50): array
    {
        $items = array_values($this->objects());
        usort($items, static fn (array $a, array $b): int => strcmp(
            (string)($b['published'] ?? ''),
            (string)($a['published'] ?? '')
        ));

        $objects = [];
        foreach (array_slice($items, 0, $limit) as $meta) {
            if (!isset($meta['id']) || !is_string($meta['id'])) {
                continue;
            }

            $object = $this->findByIdOrAlias($meta['id']);
            if ($object !== null) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    public function all(): array
    {
        return $this->recent(PHP_INT_MAX);
    }

    public function byActor(string $actorId, int $limit = 50): array
    {
        return $this->byAnyActor([$actorId], $limit);
    }

    public function byAnyActor(array $actorIds, int $limit = 50): array
    {
        $ids = [];
        foreach ($actorIds as $actorId) {
            if (is_string($actorId)) {
                $ids = array_merge($ids, $this->byActorIndex()[$actorId] ?? []);
            }
        }

        $ids = array_values(array_unique($ids));
        $metas = [];

        foreach ($ids as $id) {
            if (is_string($id) && isset($this->objects()[$id])) {
                $metas[] = $this->objects()[$id];
            }
        }

        usort($metas, static fn (array $a, array $b): int => strcmp(
            (string)($b['published'] ?? ''),
            (string)($a['published'] ?? '')
        ));

        $objects = [];
        foreach (array_slice($metas, 0, $limit) as $meta) {
            if (!isset($meta['id']) || !is_string($meta['id'])) {
                continue;
            }

            $object = $this->findByIdOrAlias($meta['id']);
            if ($object !== null) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    private function objects(): array
    {
        return $this->objects ??= $this->index('objects');
    }

    private function aliases(): array
    {
        return $this->aliases ??= $this->index('aliases');
    }

    private function children(): array
    {
        return $this->children ??= $this->index('children');
    }

    private function byActorIndex(): array
    {
        return $this->byActor ??= $this->index('by_actor');
    }

    private function index(string $name): array
    {
        $path = $this->store->dataDir() . '/indexes/' . $name . '.json';

        return is_file($path) ? $this->store->readJson($path) : [];
    }
}
