<?php

namespace Oannes;

final class SocialRelationService
{
    private array $stateCache = [];

    public function __construct(private readonly FileStore $store)
    {
    }

    public function state(string $uid, string $actorId): array
    {
        $key = $uid . "\n" . $actorId;
        if (array_key_exists($key, $this->stateCache)) {
            return $this->stateCache[$key];
        }

        $path = $this->path($uid, $actorId);

        if (!is_file($path)) {
            return $this->stateCache[$key] = [
                'actor' => $actorId,
                'muted' => false,
                'blocked' => false,
            ];
        }

        $state = $this->store->readJson($path);

        return $this->stateCache[$key] = [
            'actor' => is_string($state['actor'] ?? null) ? $state['actor'] : $actorId,
            'muted' => (bool)($state['muted'] ?? false),
            'blocked' => (bool)($state['blocked'] ?? false),
            'updated_at' => (string)($state['updated_at'] ?? ''),
        ];
    }

    public function setMuted(string $uid, string $actorId, bool $muted): array
    {
        $state = $this->state($uid, $actorId);
        $state['actor'] = $actorId;
        $state['muted'] = $muted;
        $state['updated_at'] = gmdate('c');
        $this->store->writeJson($this->path($uid, $actorId), $state);
        $this->stateCache[$uid . "\n" . $actorId] = $state;

        return $state;
    }

    public function setBlocked(string $uid, string $actorId, bool $blocked): array
    {
        $state = $this->state($uid, $actorId);
        $state['actor'] = $actorId;
        $state['blocked'] = $blocked;
        $state['updated_at'] = gmdate('c');
        $this->store->writeJson($this->path($uid, $actorId), $state);
        $this->stateCache[$uid . "\n" . $actorId] = $state;

        return $state;
    }

    public function isMuted(string $uid, string $actorId): bool
    {
        return (bool)$this->state($uid, $actorId)['muted'];
    }

    public function isBlocked(string $uid, string $actorId): bool
    {
        return (bool)$this->state($uid, $actorId)['blocked'];
    }

    public function isAnyBlocked(string $uid, array $actorIds): bool
    {
        foreach ($actorIds as $actorId) {
            if (is_string($actorId) && $actorId !== '' && $this->isBlocked($uid, $actorId)) {
                return true;
            }
        }

        return false;
    }

    private function path(string $uid, string $actorId): string
    {
        return $this->store->dataDir() . '/social/' . rawurlencode($uid) . '/relations/' . Id::digest($actorId) . '.json';
    }
}
