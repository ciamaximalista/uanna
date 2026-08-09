<?php

namespace Oannes;

final class SocialGraph
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function addFollower(string $uid, array $actor): void
    {
        $id = ActivityPub::objectId($actor);

        if ($id === null) {
            return;
        }

        $this->store->writeJson($this->path('followers', $uid, $id), $actor);
    }

    public function addFollowing(string $uid, array $actor): void
    {
        $id = ActivityPub::objectId($actor);

        if ($id === null || ActivityPub::objectType($actor) !== 'Person' && ActivityPub::objectType($actor) !== 'Service') {
            return;
        }

        $this->store->writeJson($this->path('following', $uid, $id), $actor);
    }

    public function isFollowing(string $uid, string $actorId): bool
    {
        foreach ($this->following($uid) as $actor) {
            if (in_array($actorId, ActivityPub::aliases($actor), true)) {
                return true;
            }
        }

        return false;
    }

    public function isFollower(string $uid, string $actorId): bool
    {
        foreach ($this->followers($uid) as $actor) {
            if (in_array($actorId, ActivityPub::aliases($actor), true)) {
                return true;
            }
        }

        return false;
    }

    public function removeFollowing(string $uid, string $actorId): void
    {
        foreach ($this->following($uid) as $actor) {
            if (in_array($actorId, ActivityPub::aliases($actor), true)) {
                $id = ActivityPub::objectId($actor);
                if ($id !== null) {
                    $path = $this->path('following', $uid, $id);
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    public function followers(string $uid): array
    {
        return $this->readActors('followers', $uid);
    }

    public function following(string $uid): array
    {
        return $this->readActors('following', $uid);
    }

    public function followerInboxes(string $uid): array
    {
        $inboxes = [];

        foreach ($this->followers($uid) as $actor) {
            $inbox = $this->inboxForActor($actor);
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }

        return array_values(array_unique($inboxes));
    }

    public function inboxForActor(array $actor): ?string
    {
        $shared = $actor['endpoints']['sharedInbox'] ?? null;
        if (is_string($shared) && $shared !== '') {
            return $shared;
        }

        $inbox = $actor['inbox'] ?? null;
        return is_string($inbox) && $inbox !== '' ? $inbox : null;
    }

    private function readActors(string $kind, string $uid): array
    {
        $actors = [];
        $dir = $this->store->dataDir() . '/social/' . $uid . '/' . $kind;

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $actors[] = $this->store->readJson($file);
        }

        return $actors;
    }

    private function path(string $kind, string $uid, string $actorId): string
    {
        return $this->store->dataDir() . '/social/' . $uid . '/' . $kind . '/' . Id::digest($actorId) . '.json';
    }
}
