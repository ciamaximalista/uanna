<?php

namespace Oannes;

final class LocalUsers
{
    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function all(): array
    {
        $users = [];
        $dir = $this->store->dataDir() . '/actors/local';

        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $user = $this->store->readJson($file);
            $uid = $user['uid'] ?? basename($file, '.json');

            if (is_string($uid) && $uid !== '') {
                $users[$uid] = $user;
            }
        }

        ksort($users);
        return $users;
    }

    public function find(string $uid): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            return null;
        }

        $file = $this->store->dataDir() . '/actors/local/' . $uid . '.json';
        return is_file($file) ? $this->store->readJson($file) : null;
    }

    public function create(string $uid, string $name = '', bool $admin = false): array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new \RuntimeException('Nombre de usuario no válido.');
        }

        if ($this->find($uid) !== null) {
            throw new \RuntimeException('El usuario ya existe.');
        }

        $user = [
            'uid' => $uid,
            'name' => $name !== '' ? $name : $uid,
            'bio' => '',
            'avatar' => '',
            'header' => '',
            'email' => '',
            'lang' => (string)($this->config['default_locale'] ?? 'es'),
            'tz' => (string)($this->config['timezone'] ?? 'Europe/Madrid'),
            'approve_followers' => true,
            'admin' => $admin,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
        ];

        $this->store->writeJson($this->store->dataDir() . '/actors/local/' . $uid . '.json', $user);
        (new KeyStore($this->store))->ensure($uid);
        $this->connectLocalUsers($uid, $user);

        return $user;
    }

    private function connectLocalUsers(string $newUid, array $newUser): void
    {
        $graph = new SocialGraph($this->store);
        $newActor = $this->activityPubActor($newUid, $newUser);

        foreach ($this->all() as $uid => $user) {
            if (!is_string($uid) || $uid === $newUid || !is_array($user)) {
                continue;
            }

            $actor = $this->activityPubActor($uid, $user);
            $graph->addFollowing($newUid, $actor);
            $graph->addFollower($uid, $newActor);
            $graph->addFollowing($uid, $newActor);
            $graph->addFollower($newUid, $actor);
        }
    }

    public function delete(string $uid): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new \RuntimeException('Usuario no válido.');
        }

        $path = $this->store->dataDir() . '/actors/local/' . $uid . '.json';
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function updateProfile(string $uid, array $fields): array
    {
        $user = $this->find($uid);

        if ($user === null) {
            throw new \RuntimeException('Usuario local no encontrado.');
        }

        foreach (['name', 'bio', 'avatar', 'header', 'email', 'lang', 'tz'] as $field) {
            if (array_key_exists($field, $fields)) {
                $user[$field] = trim((string)$fields[$field]);
            }
        }

        if (array_key_exists('approve_followers', $fields)) {
            $user['approve_followers'] = (bool)$fields['approve_followers'];
        }

        $user['updated_at'] = gmdate('c');
        $this->store->writeJson($this->store->dataDir() . '/actors/local/' . $uid . '.json', $user);

        return $user;
    }

    public function setAdmin(string $uid, bool $admin): array
    {
        $user = $this->find($uid);

        if ($user === null) {
            throw new \RuntimeException('Usuario local no encontrado.');
        }

        $user['admin'] = $admin;
        $user['updated_at'] = gmdate('c');
        $this->store->writeJson($this->store->dataDir() . '/actors/local/' . $uid . '.json', $user);

        return $user;
    }

    public function actorId(string $uid): string
    {
        return rtrim((string)$this->config['base_url'], '/') . (string)$this->config['local_actor_path'] . '/' . $uid;
    }

    public function legacyActorIds(string $uid): array
    {
        $base = rtrim((string)$this->config['base_url'], '/');
        $paths = $this->config['legacy_actor_paths'] ?? [];
        $ids = [];

        if (!is_array($paths)) {
            return $ids;
        }

        foreach ($paths as $path) {
            if (is_string($path)) {
                $ids[] = $base . sprintf($path, $uid);
            }
        }

        return array_values(array_unique($ids));
    }

    public function webUrl(string $uid): string
    {
        return rtrim((string)$this->config['base_url'], '/') . '/@' . rawurlencode($uid);
    }

    public function activityPubActor(string $uid, array $user): array
    {
        $actorId = $this->actorId($uid);
        $avatar = $this->avatarUrl($user);
        $header = (string)($user['header'] ?? '');

        $actor = [
            '@context' => [
                'https://www.w3.org/ns/activitystreams',
                'https://w3id.org/security/v1',
            ],
            'id' => $actorId,
            'type' => 'Person',
            'preferredUsername' => $uid,
            'name' => (string)($user['name'] ?? $uid),
            'summary' => (string)($user['bio'] ?? ''),
            'url' => $this->webUrl($uid),
            'inbox' => $actorId . '/inbox',
            'outbox' => $actorId . '/outbox',
            'followers' => $actorId . '/followers',
            'following' => $actorId . '/following',
            'manuallyApprovesFollowers' => (bool)($user['approve_followers'] ?? true),
            'discoverable' => false,
        ];

        $aliases = array_values(array_filter(
            $this->legacyActorIds($uid),
            static fn (string $id): bool => $id !== $actorId
        ));
        if ($aliases !== []) {
            $actor['alsoKnownAs'] = $aliases;
        }

        $actor['icon'] = [
            'type' => 'Image',
            'mediaType' => str_ends_with(strtolower(parse_url($avatar, PHP_URL_PATH) ?: ''), '.png') ? 'image/png' : 'image/jpeg',
            'url' => $avatar,
        ];

        if ($header !== '') {
            $actor['image'] = [
                'type' => 'Image',
                'mediaType' => 'image/jpeg',
                'url' => $header,
            ];
        }

        $publicKey = (new KeyStore($this->store))->publicKey($uid);

        if ($publicKey !== null) {
            $actor['publicKey'] = [
                'id' => $actorId . '#main-key',
                'owner' => $actorId,
                'publicKeyPem' => $publicKey,
            ];
        }

        return $actor;
    }

    public function avatarUrl(array $user): string
    {
        $avatar = (string)($user['avatar'] ?? '');
        if ($avatar !== '') {
            return $avatar;
        }

        $path = (new InstanceSettings($this->store, $this->config))->defaultAvatarPath();
        return rtrim((string)$this->config['base_url'], '/') . $path;
    }

    public function defaultHeaderUrl(): string
    {
        $path = (new InstanceSettings($this->store, $this->config))->defaultHeaderPath();
        return $path !== '' ? rtrim((string)$this->config['base_url'], '/') . $path : '';
    }
}
