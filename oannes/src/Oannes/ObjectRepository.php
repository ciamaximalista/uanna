<?php

namespace Oannes;

final class ObjectRepository
{
    private ?array $objects = null;
    private ?array $aliases = null;
    private ?array $children = null;
    private ?array $byActor = null;
    private ?array $sortedObjects = null;

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
        $objects = [];
        foreach (array_slice($this->sortedObjects(), 0, $limit) as $meta) {
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
        $metas = [];

        foreach ($this->idsByAnyActor($actorIds) as $id) {
            if (is_string($id) && isset($this->objects()[$id])) {
                $metas[] = $this->objects()[$id];
            }
        }

        usort($metas, static fn (array $a, array $b): int => strcmp(
            (string)($b['sort_date'] ?? $b['published'] ?? ''),
            (string)($a['sort_date'] ?? $a['published'] ?? '')
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

    public function countByAnyActor(array $actorIds): int
    {
        return count($this->idsByAnyActor($actorIds));
    }

    public function byTag(string $tag, int $limit = 50, int $offset = 0): array
    {
        $tag = mb_strtolower(trim(ltrim($tag, "# \t\n\r\0\x0B")));
        if ($tag === '') {
            return [];
        }

        $objects = [];
        $seen = 0;

        foreach ($this->sortedObjects() as $meta) {
            $tags = $meta['tags'] ?? [];
            if (!is_array($tags) || !in_array($tag, $tags, true)) {
                continue;
            }

            if ($seen++ < $offset) {
                continue;
            }

            if (!isset($meta['id']) || !is_string($meta['id'])) {
                continue;
            }

            $object = $this->findByIdOrAlias($meta['id']);
            if ($object !== null) {
                $objects[] = $object;
            }

            if (count($objects) >= $limit) {
                break;
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

    private function sortedObjects(): array
    {
        if ($this->sortedObjects !== null) {
            return $this->sortedObjects;
        }

        $items = array_values($this->objects());
        usort($items, static fn (array $a, array $b): int => strcmp(
            (string)($b['sort_date'] ?? $b['published'] ?? ''),
            (string)($a['sort_date'] ?? $a['published'] ?? '')
        ));

        return $this->sortedObjects = $items;
    }

    private function idsByAnyActor(array $actorIds): array
    {
        $ids = [];
        foreach ($actorIds as $actorId) {
            if (is_string($actorId)) {
                $ids = array_merge($ids, $this->byActorIndex()[$actorId] ?? []);
            }
        }

        return array_values(array_unique($ids));
    }

    private function index(string $name): array
    {
        $path = $this->store->dataDir() . '/indexes/' . $name . '.json';

        return is_file($path) ? $this->store->readJson($path) : [];
    }
}
