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

            $meta = [
                'id' => $id,
                'type' => ActivityPub::objectType($object),
                'path' => $this->relativePath($file),
                'published' => ActivityPub::published($object),
                'sort_date' => $this->sortDate($object),
                'actor' => ActivityPub::attributedTo($object),
                'inReplyTo' => ActivityPub::inReplyTo($object),
            ];

            $tags = $this->tags($object);
            if ($tags !== []) {
                $meta['tags'] = $tags;
            }

            $objects[$id] = $meta;

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
                    $objects[$a]['sort_date'] ?? $objects[$a]['published'] ?? '',
                    $objects[$b]['sort_date'] ?? $objects[$b]['published'] ?? ''
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

    private function sortDate(array $object): string
    {
        foreach (['_oannes_boosted_at', '_oannes_thread_activity_at', '_oannes_notified_at'] as $key) {
            $value = $object[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return ActivityPub::published($object);
    }

    private function tags(array $object): array
    {
        $tags = [];

        foreach ((array)($object['tag'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? '';
            $type = $entry['type'] ?? '';
            if (is_string($name) && ($type === 'Hashtag' || str_starts_with($name, '#'))) {
                $tag = $this->normalizeTag($name);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        foreach (['sourceContent', 'content', 'summary', 'name'] as $field) {
            $value = $object[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            preg_match_all('/(?<![\p{L}\p{N}_&])#([\p{L}\p{N}_][\p{L}\p{N}_-]{0,63})(?![\p{L}\p{N}_-])/u', strip_tags($value), $matches);
            foreach ($matches[1] ?? [] as $candidate) {
                $tag = $this->normalizeTag((string)$candidate);
                if ($tag !== '') {
                    $tags[$tag] = true;
                }
            }
        }

        return array_keys($tags);
    }

    private function normalizeTag(string $tag): string
    {
        return mb_strtolower(trim(ltrim($tag, "# \t\n\r\0\x0B")));
    }
}
