<?php

namespace Oannes;

final class ActorUpdateService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly FileQueue $queue,
        private readonly SocialGraph $graph,
        private readonly array $config,
    ) {
    }

    public function enqueue(string $uid, array $extraActors = []): int
    {
        $user = $this->users->find($uid);

        if ($user === null) {
            throw new \RuntimeException('Usuario local no encontrado.');
        }

        $actor = $this->users->activityPubActor($uid, $user);
        $actorId = ActivityPub::objectId($actor);

        if ($actorId === null) {
            throw new \RuntimeException('El actor local no tiene id.');
        }

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '#update-' . gmdate('YmdHis'),
            'type' => 'Update',
            'actor' => $actorId,
            'published' => gmdate('c'),
            'to' => [ActivityPub::PUBLIC_AUDIENCE],
            'cc' => [$actorId . '/followers'],
            'object' => $actor,
        ];

        $inboxes = $this->remoteFollowerInboxes($uid);
        foreach ($extraActors as $extraActor) {
            if (!is_string($extraActor) || $extraActor === '' || $this->localUidForActor($extraActor) !== null) {
                continue;
            }

            $inbox = $this->inboxForActorId($extraActor);
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }

        $count = 0;
        foreach (array_values(array_unique($inboxes)) as $inbox) {
            $this->queue->enqueue('deliver', [
                'actor' => $actorId,
                'inbox' => $inbox,
                'activity' => $activity,
            ]);
            $count++;
        }

        return $count;
    }

    private function remoteFollowerInboxes(string $uid): array
    {
        $inboxes = [];

        foreach ($this->graph->followers($uid) as $actor) {
            if ($this->isLocalActor($actor)) {
                continue;
            }

            $inbox = $this->graph->inboxForActor($actor);
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }

        return array_values(array_unique($inboxes));
    }

    private function inboxForActorId(string $actorId): ?string
    {
        $actor = (new ActorRepository($this->store))->findById($actorId);

        if ($actor === null && str_starts_with($actorId, 'https://')) {
            try {
                $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($actorId);
            } catch (\Throwable) {
                return null;
            }
        }

        return is_array($actor) ? $this->graph->inboxForActor($actor) : null;
    }

    private function isLocalActor(array $actor): bool
    {
        foreach (ActivityPub::aliases($actor) as $alias) {
            if ($this->localUidForActor($alias) !== null) {
                return true;
            }
        }

        return false;
    }

    private function localUidForActor(string $actorId): ?string
    {
        foreach ($this->users->all() as $uid => $user) {
            $uid = (string)$uid;
            $ids = array_merge([$this->users->actorId($uid), $this->users->webUrl($uid)], $this->users->legacyActorIds($uid));

            if (in_array($actorId, $ids, true)) {
                return $uid;
            }
        }

        return null;
    }
}
