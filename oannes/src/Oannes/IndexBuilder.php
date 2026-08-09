<?php

namespace Oannes;

final class IndexBuilder
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function rebuild(): array
    {
        $objects = [];
        $aliases = [];
        $children = [];
        $byActor = [];
        $orphans = [];

        foreach ($this->store->objectFiles() as $file) {
            $object = $this->store->readJson($file);
            $id = ActivityPub::objectId($object);

            if ($id === null) {
                continue;
            }

            $objects[$id] = [
                'id' => $id,
                'type' => ActivityPub::objectType($object),
                'path' => $this->relativePath($file),
                'published' => ActivityPub::published($object),
                'actor' => ActivityPub::attributedTo($object),
                'inReplyTo' => ActivityPub::inReplyTo($object),
            ];

            foreach (ActivityPub::aliases($object) as $alias) {
                $aliases[$alias] = $id;
            }

            $actor = ActivityPub::attributedTo($object);
            if ($actor !== null) {
                $byActor[$actor][] = $id;
            }
        }

        foreach ($objects as $id => $meta) {
            $parent = $meta['inReplyTo'];

            if ($parent === null) {
                continue;
            }

            $canonicalParent = $aliases[$parent] ?? $parent;

            if (isset($objects[$canonicalParent])) {
                $children[$canonicalParent][] = $id;
            } else {
                $orphans[$parent][] = $id;
            }
        }

        foreach ([$children, $byActor, $orphans] as &$bucket) {
            foreach ($bucket as &$ids) {
                usort($ids, fn (string $a, string $b): int => strcmp(
                    $objects[$a]['published'] ?? '',
                    $objects[$b]['published'] ?? ''
                ));
            }
        }
        unset($bucket, $ids);

        $manifest = [
            'counts' => [
                'objects' => count($objects),
                'aliases' => count($aliases),
                'parents' => count($children),
                'actors' => count($byActor),
                'orphan_parent_refs' => count($orphans),
            ],
            'generated_at' => gmdate('c'),
            'rules' => [
                'reply_parentage' => 'Only ActivityPub inReplyTo creates child indexes.',
                'ignored_for_parentage' => ['content links', 'sourceContent links', 'attachment.href', 'url cards'],
            ],
        ];

        $this->store->writeJson($this->store->dataDir() . '/indexes/objects.json', $objects);
        $this->store->writeJson($this->store->dataDir() . '/indexes/aliases.json', $aliases);
        $this->store->writeJson($this->store->dataDir() . '/indexes/children.json', $children);
        $this->store->writeJson($this->store->dataDir() . '/indexes/by_actor.json', $byActor);
        $this->store->writeJson($this->store->dataDir() . '/indexes/orphans.json', $orphans);
        $this->store->writeJson($this->store->dataDir() . '/indexes/manifest.json', $manifest);

        return [
            'objects' => count($objects),
            'aliases' => count($aliases),
            'parents' => count($children),
            'orphan_parent_refs' => count($orphans),
        ];
    }

    private function relativePath(string $path): string
    {
        $root = rtrim($this->store->dataDir(), '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
