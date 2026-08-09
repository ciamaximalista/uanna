<?php

namespace Oannes;

final class InteractionService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly FileQueue $queue,
        private readonly SocialGraph $graph,
        private readonly ActorRepository $actors,
        private readonly array $config,
    ) {
    }

    public function counts(array $object): array
    {
        $id = ActivityPub::objectId($object) ?? '';
        $likes = $this->collectionCount($object['likes'] ?? null);
        $boosts = $this->collectionCount($object['shares'] ?? $object['announces'] ?? null);

        if ($id !== '') {
            foreach ($this->storedInteractionsFor($id) as $interaction) {
                $type = $interaction['type'] ?? '';
                if ($type === 'Like') {
                    $likes++;
                } elseif ($type === 'Announce') {
                    $boosts++;
                }
            }
        }

        return [
            'likes' => $likes,
            'boosts' => $boosts,
        ];
    }

    public function actors(array $object): array
    {
        $id = ActivityPub::objectId($object) ?? '';
        $actors = [
            'likes' => [],
            'boosts' => [],
        ];

        if ($id === '') {
            return $actors;
        }

        foreach ($this->storedInteractionsFor($id) as $interaction) {
            $actor = $interaction['actor'] ?? null;
            $type = $interaction['type'] ?? '';

            if (!is_string($actor) || $actor === '') {
                continue;
            }

            if ($type === 'Like') {
                $actors['likes'][] = $actor;
            } elseif ($type === 'Announce') {
                $actors['boosts'][] = $actor;
            }
        }

        $actors['likes'] = array_values(array_unique($actors['likes']));
        $actors['boosts'] = array_values(array_unique($actors['boosts']));

        return $actors;
    }

    public function boostedObjectsByUser(string $uid, int $limit = 50): array
    {
        $objects = [];
        $repo = new ObjectRepository($this->store);

        foreach (glob($this->store->dataDir() . '/interactions/local/' . $uid . '/*.json') ?: [] as $file) {
            $activity = $this->readJsonFile($file);

            if (($activity['type'] ?? null) !== 'Announce' || !is_string($activity['object'] ?? null)) {
                continue;
            }

            $object = $repo->findByIdOrAlias($activity['object']);
            if ($object === null) {
                continue;
            }

            $object['_oannes_boosted_at'] = is_string($activity['published'] ?? null) ? $activity['published'] : ActivityPub::published($object);
            $object['_oannes_boosted_by'] = $this->users->actorId($uid);
            $objects[] = $object;
        }

        usort($objects, static fn (array $a, array $b): int => strcmp(
            (string)($b['_oannes_boosted_at'] ?? ActivityPub::published($b)),
            (string)($a['_oannes_boosted_at'] ?? ActivityPub::published($a))
        ));

        return array_slice($objects, 0, $limit);
    }

    public function react(string $uid, string $objectId, string $type): array
    {
        if (!in_array($type, ['Like', 'Announce'], true)) {
            throw new \InvalidArgumentException('Interacción no válida.');
        }

        $user = $this->users->find($uid);
        if ($user === null) {
            throw new \InvalidArgumentException('Usuario local desconocido.');
        }

        $repo = new ObjectRepository($this->store);
        $object = $repo->findByIdOrAlias($objectId);
        if ($object === null) {
            throw new \InvalidArgumentException('Mensaje no encontrado.');
        }

        $canonicalObjectId = ActivityPub::objectId($object) ?? $objectId;
        $actorId = $this->users->actorId($uid);
        $stamp = sprintf('%.6F', microtime(true));
        $id = rtrim((string)$this->config['base_url'], '/') . '/u/' . rawurlencode($uid)
            . '/activity/' . strtolower($type) . '/' . $stamp;

        $existing = $this->localPath($uid, $type, $canonicalObjectId);
        if (is_file($existing)) {
            return [
                'ok' => true,
                'type' => $type,
                'object' => $canonicalObjectId,
                'already_present' => true,
            ];
        }

        $activity = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id,
            'type' => $type,
            'actor' => $actorId,
            'object' => $canonicalObjectId,
            'published' => gmdate('c'),
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        ];

        if ($type === 'Announce') {
            $activity['cc'] = [$actorId . '/followers'];
        }

        $this->storeLocal($uid, $activity);
        $this->enqueueDeliveries($uid, $activity, $object);

        return [
            'ok' => true,
            'type' => $type,
            'object' => $canonicalObjectId,
        ];
    }

    private function collectionCount(mixed $collection): int
    {
        if (is_array($collection)) {
            $total = $collection['totalItems'] ?? null;
            return is_int($total) ? $total : (is_numeric($total) ? (int)$total : 0);
        }

        return 0;
    }

    private function storedInteractionsFor(string $objectId): array
    {
        $items = [];

        foreach (glob(dirname($this->store->dataDir()) . '/../user/*/notify/*.json') ?: [] as $file) {
            $record = $this->readJsonFile($file);
            $activity = is_array($record['msg'] ?? null) ? $record['msg'] : $record;
            $target = $record['objid'] ?? $activity['object'] ?? null;

            if (is_string($target) && $target === $objectId && in_array($activity['type'] ?? '', ['Like', 'Announce'], true)) {
                $items[] = $activity;
            }
        }

        foreach (glob($this->store->dataDir() . '/interactions/local/*/*.json') ?: [] as $file) {
            $activity = $this->readJsonFile($file);
            $target = $activity['object'] ?? null;

            if (is_string($target) && $target === $objectId && in_array($activity['type'] ?? '', ['Like', 'Announce'], true)) {
                $items[] = $activity;
            }
        }

        return $items;
    }

    private function storeLocal(string $uid, array $activity): void
    {
        $type = (string)($activity['type'] ?? '');
        $objectId = (string)($activity['object'] ?? '');
        $path = $this->localPath($uid, $type, $objectId);
        $this->store->writeJson($path, $activity);
    }

    private function localPath(string $uid, string $type, string $objectId): string
    {
        return $this->store->dataDir() . '/interactions/local/' . $uid . '/' . Id::digest($type . ':' . $objectId) . '.json';
    }

    private function enqueueDeliveries(string $uid, array $activity, array $object): void
    {
        $actorId = $this->users->actorId($uid);
        $inboxes = [];
        $objectActor = ActivityPub::attributedTo($object);

        if ($objectActor !== null) {
            $actor = $this->actors->findById($objectActor);
            if ($actor !== null) {
                $inbox = $this->graph->inboxForActor($actor);
                if ($inbox !== null) {
                    $inboxes[] = $inbox;
                }
            }
        }

        if (($activity['type'] ?? '') === 'Announce') {
            $inboxes = array_merge($inboxes, $this->graph->followerInboxes($uid));
        }

        foreach (array_values(array_unique($inboxes)) as $inbox) {
            $this->queue->enqueue('deliver', [
                'actor' => $actorId,
                'inbox' => $inbox,
                'activity' => $activity,
            ]);
        }
    }

    private function readJsonFile(string $path): array
    {
        try {
            $json = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            return is_array($json) ? $json : [];
        } catch (\Throwable) {
            return [];
        }
    }
}
