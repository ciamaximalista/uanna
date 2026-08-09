<?php

namespace Oannes;

final class InstanceSettings
{
    public const DEFAULT_PRESENTATION_HTML = '$Nombre es una comunidad de micro-blogging dentro del Fediverso. Utilizamos <a href="https://ruralnext.org/uanna">Uanna</a>, un programa ligero de software libre, sin bases de datos ni complicaciones, para dar servicio a nuestros $Numero usuarios:<br><br>$Avatares';

    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function all(): array
    {
        $path = $this->path('settings.json');
        return is_file($path) ? $this->store->readJson($path) : [];
    }

    public function get(string $key, string $fallback): string
    {
        $settings = $this->all();
        $value = $settings[$key] ?? null;
        return is_string($value) && $value !== '' ? $value : $fallback;
    }

    public function update(array $fields): array
    {
        $settings = $this->all();

        foreach (['favicon', 'default_avatar', 'default_header'] as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field]) && $fields[$field] !== '') {
                $settings[$field] = $fields[$field];
            }
        }

        foreach (['instance_name', 'presentation_html'] as $field) {
            if (array_key_exists($field, $fields) && is_string($fields[$field])) {
                $settings[$field] = trim($fields[$field]);
            }
        }

        $settings['updated_at'] = gmdate('c');
        $this->store->writeJson($this->path('settings.json'), $settings);

        return $settings;
    }

    public function faviconPath(): string
    {
        return $this->get('favicon', (string)($this->config['default_avatar_path'] ?? '/uanna.png'));
    }

    public function defaultAvatarPath(): string
    {
        return $this->get('default_avatar', (string)($this->config['default_avatar_path'] ?? '/uanna.png'));
    }

    public function defaultHeaderPath(): string
    {
        return $this->get('default_header', (string)($this->config['default_header_path'] ?? ''));
    }

    public function instanceName(): string
    {
        return $this->get('instance_name', (string)($this->config['software_name'] ?? 'Uanna'));
    }

    public function presentationHtml(): string
    {
        return $this->get('presentation_html', self::DEFAULT_PRESENTATION_HTML);
    }

    public function blockedServers(): array
    {
        return $this->readList('blocked_servers.json');
    }

    public function addBlockedServer(string $server): void
    {
        $server = strtolower(trim($server));
        if ($server === '') {
            throw new \RuntimeException('Servidor no válido.');
        }

        $this->writeList('blocked_servers.json', array_values(array_unique([...$this->blockedServers(), $server])));
    }

    public function removeBlockedServer(string $server): void
    {
        $server = strtolower(trim($server));
        $this->writeList('blocked_servers.json', array_values(array_filter(
            $this->blockedServers(),
            static fn (string $item): bool => $item !== $server
        )));
    }

    public function blockedActors(): array
    {
        return $this->readList('blocked_actors.json');
    }

    public function addBlockedActor(string $actor): void
    {
        if ($actor === '') {
            throw new \RuntimeException('Actor no válido.');
        }

        $this->writeList('blocked_actors.json', array_values(array_unique([...$this->blockedActors(), $actor])));
    }

    public function isActorBlocked(string $actor): bool
    {
        if (in_array($actor, $this->blockedActors(), true)) {
            return true;
        }

        $host = parse_url($actor, PHP_URL_HOST);
        return is_string($host) && in_array(strtolower($host), $this->blockedServers(), true);
    }

    public function blockNotices(): array
    {
        $notices = [];
        foreach (glob($this->store->dataDir() . '/instance/block-notices/*.json') ?: [] as $file) {
            $notices[] = $this->store->readJson($file);
        }

        usort($notices, static fn (array $a, array $b): int => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
        return $notices;
    }

    public function recordUserBlock(string $uid, string $actor): void
    {
        $path = $this->store->dataDir() . '/instance/block-notices/' . Id::digest($actor) . '.json';
        $notice = is_file($path) ? $this->store->readJson($path) : [
            'actor' => $actor,
            'blocked_by' => [],
            'created_at' => gmdate('c'),
        ];
        $blockedBy = is_array($notice['blocked_by'] ?? null) ? $notice['blocked_by'] : [];
        $blockedBy[] = $uid;
        $notice['blocked_by'] = array_values(array_unique($blockedBy));
        $notice['updated_at'] = gmdate('c');
        $this->store->writeJson($path, $notice);
    }

    private function readList(string $file): array
    {
        $path = $this->path($file);
        $items = is_file($path) ? $this->store->readJson($path) : [];
        return array_values(array_filter($items, static fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private function writeList(string $file, array $items): void
    {
        sort($items);
        $this->store->writeJson($this->path($file), $items);
    }

    private function path(string $file): string
    {
        return $this->store->dataDir() . '/instance/' . $file;
    }
}
