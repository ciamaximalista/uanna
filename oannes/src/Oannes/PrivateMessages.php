<?php

namespace Oannes;

/**
 * Per-user store of private messages (non-public notes addressed to a local
 * user directly, not through a followers collection). It mirrors the
 * canonical object into data/users/<uid>/private so the panel can list
 * conversations without scanning every object.
 */
final class PrivateMessages
{
    private ?array $localActorIds = null;

    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
    ) {
    }

    public function isPrivate(array $object): bool
    {
        $audience = ActivityPub::audience($object);

        if ($audience === [] || in_array(ActivityPub::PUBLIC_AUDIENCE, $audience, true)) {
            return false;
        }

        foreach ($audience as $target) {
            if (str_ends_with($target, '/followers')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Local uids explicitly addressed by the object (to/cc/bto/bcc or a
     * Mention tag), excluding the author.
     */
    public function localRecipients(array $object): array
    {
        $targets = ActivityPub::audience($object);

        foreach (is_array($object['tag'] ?? null) ? $object['tag'] : [] as $tag) {
            if (is_array($tag) && ($tag['type'] ?? null) === 'Mention' && is_string($tag['href'] ?? null)) {
                $targets[] = $tag['href'];
            }
        }

        $author = ActivityPub::attributedTo($object);
        $uids = [];

        foreach ($targets as $target) {
            $uid = $this->localUidForActor($target);
            if ($uid !== null && ($author === null || $this->localUidForActor($author) !== $uid)) {
                $uids[$uid] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * Stores a private object for each local recipient. Returns the uids
     * indexed, or [] when the object is not a private message.
     */
    public function index(array $object): array
    {
        $id = ActivityPub::objectId($object);
        if ($id === null || !$this->isPrivate($object)) {
            return [];
        }

        $hash = md5($id);
        $uids = $this->localRecipients($object);

        foreach ($uids as $uid) {
            $dir = $this->store->dataDir() . '/users/' . rawurlencode($uid);
            $this->store->writeJson($dir . '/private/' . $hash . '.json', $object);
            $this->appendIndex($dir . '/private.idx', $hash);
        }

        return $uids;
    }

    /** Drops the per-user copies of a deleted private message. */
    public function remove(array $object): void
    {
        $id = ActivityPub::objectId($object);
        if ($id === null) {
            return;
        }

        $hash = md5($id);

        foreach ($this->localRecipients($object) as $uid) {
            $file = $this->store->dataDir() . '/users/' . rawurlencode($uid) . '/private/' . $hash . '.json';
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public function isIndexed(string $uid, array $object): bool
    {
        $id = ActivityPub::objectId($object);

        return $id !== null && is_file($this->store->dataDir() . '/users/' . rawurlencode($uid) . '/private/' . md5($id) . '.json');
    }

    public function localUidForActor(string $actorId): ?string
    {
        if ($this->localActorIds === null) {
            $this->localActorIds = [];
            foreach ($this->users->all() as $uid => $_user) {
                $uid = (string)$uid;
                foreach (array_merge([$this->users->actorId($uid), $this->users->webUrl($uid)], $this->users->legacyActorIds($uid)) as $candidate) {
                    $this->localActorIds[$candidate] = $uid;
                }
            }
        }

        return $this->localActorIds[$actorId] ?? null;
    }

    private function appendIndex(string $path, string $hash): void
    {
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $lines = array_values(array_unique(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== '')));

        if (in_array($hash, $lines, true)) {
            return;
        }

        array_unshift($lines, $hash);
        $this->store->writeText($path, implode("\n", $lines) . "\n");
    }
}
