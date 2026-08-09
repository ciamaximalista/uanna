<?php

namespace Oannes;

final class ThreadValidator
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function validate(): array
    {
        $objects = $this->store->readJson($this->store->dataDir() . '/indexes/objects.json');
        $children = $this->store->readJson($this->store->dataDir() . '/indexes/children.json');
        $aliases = $this->store->readJson($this->store->dataDir() . '/indexes/aliases.json');
        $errors = [];

        foreach ($children as $parentId => $childIds) {
            foreach ($childIds as $childId) {
                $child = $objects[$childId] ?? null;

                if (!is_array($child)) {
                    $errors[] = "Child {$childId} is indexed under {$parentId} but object metadata is missing.";
                    continue;
                }

                $replyTo = $child['inReplyTo'] ?? null;
                $canonical = is_string($replyTo) ? ($aliases[$replyTo] ?? $replyTo) : null;

                if ($canonical !== $parentId) {
                    $errors[] = "Child {$childId} is indexed under {$parentId}, but inReplyTo is {$replyTo}.";
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'checked_parent_count' => count($children),
        ];
    }
}

