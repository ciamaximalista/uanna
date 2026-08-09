<?php

namespace Oannes;

final class PostService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly FileQueue $queue,
        private readonly SocialGraph $graph,
        private readonly array $config,
    ) {
    }

    public function createNote(string $uid, string $content, array $options = []): array
    {
        $user = $this->users->find($uid);

        if ($user === null) {
            throw new \InvalidArgumentException('Unknown local user');
        }

        $content = trim($content);
        if ($content === '') {
            throw new \InvalidArgumentException('Content cannot be empty');
        }

        $now = microtime(true);
        $stamp = sprintf('%.6F', $now);
        $actorId = $this->users->actorId($uid);
        $id = rtrim((string)$this->config['base_url'], '/') . '/u/' . rawurlencode($uid) . '/p/' . $stamp;
        $followers = $actorId . '/followers';
        $visibility = $options['visibility'] ?? 'public';
        $inReplyTo = $options['inReplyTo'] ?? null;
        $directTo = $options['to'] ?? null;
        $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];
        $mentions = $this->mentionsForContent($content, $uid);
        $html = $this->contentHtml($content, $mentions);

        $to = [];
        $cc = [];

        if ($visibility === 'public') {
            $to[] = 'https://www.w3.org/ns/activitystreams#Public';
            $cc[] = $followers;
        } elseif ($visibility === 'direct' && is_string($directTo) && $directTo !== '') {
            $to[] = $directTo;
        } else {
            $to[] = $followers;
        }

        foreach ($mentions as $mention) {
            if (is_string($mention['actor'] ?? null) && $mention['actor'] !== '' && !in_array($mention['actor'], $to, true)) {
                $to[] = $mention['actor'];
            }
        }

        $note = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'type' => 'Note',
            'id' => $id,
            'published' => gmdate('c'),
            'attributedTo' => $actorId,
            'summary' => '',
            'content' => $html,
            'sourceContent' => $content,
            'url' => $id,
            'to' => $to,
            'cc' => $cc,
            'tag' => array_map(static fn (array $mention): array => [
                'type' => 'Mention',
                'href' => (string)$mention['href'],
                'name' => (string)$mention['name'],
            ], $mentions),
            'replies' => [
                'id' => $id . '/replies',
                'type' => 'Collection',
                'first' => [
                    'type' => 'CollectionPage',
                    'partOf' => $id . '/replies',
                    'items' => [],
                ],
            ],
        ];

        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

        if (is_string($inReplyTo) && $inReplyTo !== '') {
            $note['inReplyTo'] = $inReplyTo;
        }

        $this->store->writeObject($note);
        (new IndexBuilder($this->store))->rebuild();

        $create = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id . '#create',
            'type' => 'Create',
            'actor' => $actorId,
            'published' => $note['published'],
            'to' => $to,
            'cc' => $cc,
            'object' => $note,
        ];

        foreach ($this->deliveryInboxes($uid, $visibility, is_string($directTo) ? $directTo : null) as $inbox) {
            $this->queue->enqueue('deliver', [
                'actor' => $actorId,
                'inbox' => $inbox,
                'activity' => $create,
            ]);
        }

        foreach ($this->mentionInboxes($mentions) as $inbox) {
            $this->queue->enqueue('deliver', [
                'actor' => $actorId,
                'inbox' => $inbox,
                'activity' => $create,
            ]);
        }

        $this->notifyLocalMentions($uid, $note, $create, $mentions);

        return $note;
    }

    public function updateNote(string $uid, string $id, string $content, array $options = []): array
    {
        $note = (new ObjectRepository($this->store))->findByIdOrAlias($id);
        $actorId = $this->users->actorId($uid);

        if ($note === null || !in_array(ActivityPub::attributedTo($note), array_merge([$actorId], $this->users->legacyActorIds($uid)), true)) {
            throw new \RuntimeException('No puedes editar esa publicación.');
        }

        $content = trim($content);
        if ($content === '') {
            throw new \RuntimeException('El texto no puede estar vacío.');
        }

        $mentions = $this->mentionsForContent($content, $uid);
        $note['content'] = $this->contentHtml($content, $mentions);
        $note['sourceContent'] = $content;
        $note['updated'] = gmdate('c');
        $note['tag'] = array_map(static fn (array $mention): array => [
            'type' => 'Mention',
            'href' => (string)$mention['href'],
            'name' => (string)$mention['name'],
        ], $mentions);

        $attachments = is_array($options['attachments'] ?? null) ? $options['attachments'] : [];
        if ($attachments !== []) {
            $note['attachment'] = $attachments;
        }

        foreach ($mentions as $mention) {
            if (is_string($mention['actor'] ?? null) && $mention['actor'] !== '' && !in_array($mention['actor'], $note['to'] ?? [], true)) {
                $note['to'][] = $mention['actor'];
            }
        }

        $this->store->writeObject($note);
        (new IndexBuilder($this->store))->rebuild();

        $update = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id . '#update-' . gmdate('YmdHis'),
            'type' => 'Update',
            'actor' => $actorId,
            'published' => gmdate('c'),
            'to' => is_array($note['to'] ?? null) ? $note['to'] : [],
            'cc' => is_array($note['cc'] ?? null) ? $note['cc'] : [],
            'object' => $note,
        ];

        $this->enqueueAudience($uid, $note, $update);
        $this->notifyLocalMentions($uid, $note, $update, $mentions);

        return $note;
    }

    public function deleteNote(string $uid, string $id): void
    {
        $note = (new ObjectRepository($this->store))->findByIdOrAlias($id);
        $actorId = $this->users->actorId($uid);

        if ($note === null || !in_array(ActivityPub::attributedTo($note), array_merge([$actorId], $this->users->legacyActorIds($uid)), true)) {
            throw new \RuntimeException('No puedes borrar esa publicación.');
        }

        $delete = [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id . '#delete-' . gmdate('YmdHis'),
            'type' => 'Delete',
            'actor' => $actorId,
            'published' => gmdate('c'),
            'to' => is_array($note['to'] ?? null) ? $note['to'] : [],
            'cc' => is_array($note['cc'] ?? null) ? $note['cc'] : [],
            'object' => [
                'id' => $id,
                'type' => 'Tombstone',
            ],
        ];

        $this->enqueueAudience($uid, $note, $delete);
        $this->store->deleteObject($id);
        (new IndexBuilder($this->store))->rebuild();
    }

    private function mentionsForContent(string $content, string $authorUid): array
    {
        preg_match_all('/(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})(?![\w@.-])/', $content, $matches, PREG_SET_ORDER);
        $mentions = [];

        foreach ($matches as $match) {
            $name = $match[0];
            $username = $match[1];
            $host = strtolower($match[2]);
            $mention = $this->resolveMention($username, $host, $name);

            if ($mention === null || $mention['local_uid'] === $authorUid) {
                continue;
            }

            $mentions[$name] = $mention;
        }

        return array_values($mentions);
    }

    private function resolveMention(string $username, string $host, string $name): ?array
    {
        if ($host === strtolower((string)$this->config['host'])) {
            $user = $this->users->find($username);
            if ($user === null) {
                return null;
            }

            return [
                'name' => $name,
                'actor' => $this->users->actorId($username),
                'href' => $this->users->webUrl($username),
                'local_uid' => $username,
            ];
        }

        $actor = $this->cachedActorByAcct($username, $host);
        if ($actor === null) {
            try {
                $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($name);
            } catch (\Throwable) {
                return null;
            }
        }

        $actorId = ActivityPub::objectId($actor);
        if ($actorId === null) {
            return null;
        }

        return [
            'name' => $name,
            'actor' => $actorId,
            'href' => $this->actorUrl($actor, $actorId),
            'local_uid' => null,
        ];
    }

    private function contentHtml(string $content, array $mentions): string
    {
        $byName = [];
        foreach ($mentions as $mention) {
            $byName[(string)$mention['name']] = $mention;
        }

        $pattern = '/(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})(?![\w@.-])|https?:\/\/[^\s<>"\']+/u';
        $html = '';
        $offset = 0;

        preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $match) {
            [$text, $position] = $match;
            $html .= Html::escape(substr($content, $offset, $position - $offset));

            if (str_starts_with($text, 'http://') || str_starts_with($text, 'https://')) {
                [$url, $suffix] = $this->splitUrlSuffix($text);
                $html .= '<a href="' . Html::escape($url) . '">' . Html::escape($url) . '</a>' . Html::escape($suffix);
                $offset = $position + strlen($text);
                continue;
            }

            if (isset($byName[$text])) {
                $mention = $byName[$text];
                $html .= '<a class="mention" href="' . Html::escape((string)$mention['href']) . '">' . Html::escape($text) . '</a>';
            } else {
                $html .= Html::escape($text);
            }

            $offset = $position + strlen($text);
        }

        $html .= Html::escape(substr($content, $offset));
        return Html::safeContent(nl2br($html, false));
    }

    private function splitUrlSuffix(string $url): array
    {
        $suffix = '';
        while ($url !== '' && preg_match('/[.,;:!?)]$/', $url) === 1) {
            $suffix = substr($url, -1) . $suffix;
            $url = substr($url, 0, -1);
        }

        return [$url, $suffix];
    }

    private function mentionInboxes(array $mentions): array
    {
        $actors = new ActorRepository($this->store);
        $graph = new SocialGraph($this->store);
        $inboxes = [];

        foreach ($mentions as $mention) {
            $actorId = is_string($mention['actor'] ?? null) ? $mention['actor'] : '';
            if ($actorId === '' || $mention['local_uid'] !== null) {
                continue;
            }

            $actor = $actors->findById($actorId);
            if ($actor === null) {
                continue;
            }

            $inbox = $graph->inboxForActor($actor);
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }

        return array_values(array_unique($inboxes));
    }

    private function enqueueAudience(string $uid, array $object, array $activity): void
    {
        foreach ($this->audienceInboxes($uid, $object) as $inbox) {
            $this->queue->enqueue('deliver', [
                'actor' => $this->users->actorId($uid),
                'inbox' => $inbox,
                'activity' => $activity,
            ]);
        }
    }

    private function audienceInboxes(string $uid, array $object): array
    {
        $inboxes = $this->graph->followerInboxes($uid);
        $actors = new ActorRepository($this->store);

        foreach (array_merge(is_array($object['to'] ?? null) ? $object['to'] : [], is_array($object['cc'] ?? null) ? $object['cc'] : []) as $actorId) {
            if (!is_string($actorId) || $actorId === '' || $actorId === 'https://www.w3.org/ns/activitystreams#Public' || str_ends_with($actorId, '/followers')) {
                continue;
            }

            $actor = $actors->findById($actorId);
            if ($actor === null) {
                continue;
            }

            $inbox = $this->graph->inboxForActor($actor);
            if ($inbox !== null) {
                $inboxes[] = $inbox;
            }
        }

        return array_values(array_unique($inboxes));
    }

    private function notifyLocalMentions(string $authorUid, array $note, array $create, array $mentions): void
    {
        $root = dirname($this->store->dataDir(), 2);

        foreach ($mentions as $mention) {
            $localUid = $mention['local_uid'] ?? null;
            if (!is_string($localUid) || $localUid === '' || $localUid === $authorUid) {
                continue;
            }

            $id = sprintf('%.6F', microtime(true));
            $this->store->writeJson($root . '/user/' . $localUid . '/notify/' . $id . '.json', [
                'id' => $id,
                'type' => 'Mention',
                'utype' => 'Create',
                'actor' => $note['attributedTo'],
                'date' => $note['published'],
                'msg' => $create,
                'objid' => $note['id'],
            ]);
        }
    }

    private function cachedActorByAcct(string $username, string $host): ?array
    {
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
                    return $actor;
                }
            }
        }

        return null;
    }

    private function actorUrl(array $actor, string $actorId): string
    {
        $url = $actor['url'] ?? null;

        if (is_string($url) && $url !== '') {
            return $url;
        }

        if (is_array($url)) {
            foreach ($url as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }

                if (is_array($item) && is_string($item['href'] ?? null)) {
                    return $item['href'];
                }
            }
        }

        return $actorId;
    }

    private function deliveryInboxes(string $uid, mixed $visibility, ?string $directTo): array
    {
        if ($visibility !== 'direct') {
            return $this->graph->followerInboxes($uid);
        }

        if ($directTo === null || $directTo === '') {
            return [];
        }

        $actor = (new ActorRepository($this->store))->findById($directTo);
        if ($actor === null) {
            throw new \InvalidArgumentException('No tengo el actor destinatario en caché. Usa un actor al que sigas o que ya haya interactuado.');
        }

        $inbox = $this->graph->inboxForActor($actor);
        if ($inbox === null) {
            throw new \InvalidArgumentException('El actor destinatario no tiene inbox.');
        }

        return [$inbox];
    }
}
