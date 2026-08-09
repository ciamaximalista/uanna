<?php

namespace Oannes;

final class ActorRepository
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function findById(string $id): ?array
    {
        $path = Id::actorPath($this->store->dataDir(), $id);

        if (is_file($path)) {
            return $this->store->readJson($path);
        }

        foreach ($this->store->actorFiles() as $file) {
            try {
                $actor = $this->store->readJson($file);
            } catch (\Throwable) {
                continue;
            }

            if (in_array($id, ActivityPub::aliases($actor), true)) {
                return $actor;
            }
        }

        return null;
    }

    public function findByPreferredUsername(string $username, string $host): array
    {
        $actors = [];

        foreach ($this->store->actorFiles() as $file) {
            try {
                $actor = $this->store->readJson($file);
            } catch (\Throwable) {
                continue;
            }

            $preferred = $actor['preferredUsername'] ?? null;
            if (!is_string($preferred) || strcasecmp($preferred, $username) !== 0) {
                continue;
            }

            foreach (ActivityPub::aliases($actor) as $alias) {
                $aliasHost = parse_url($alias, PHP_URL_HOST);
                if (is_string($aliasHost) && strcasecmp($aliasHost, $host) === 0) {
                    $actors[] = $actor;
                    break;
                }
            }
        }

        return $actors;
    }
}
