<?php

namespace Oannes;

final class LanguageCatalog
{
    private const FALLBACK_NAMES = [
        'es' => 'Español',
        'en' => 'English',
        'ca' => 'Català',
        'gl' => 'Galego',
        'eu' => 'Euskara',
        'pt' => 'Português',
        'fr' => 'Français',
        'it' => 'Italiano',
        'de' => 'Deutsch',
    ];

    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
    ) {
    }

    public function available(): array
    {
        $languages = [];

        foreach ($this->languageFiles() as $file) {
            try {
                $data = $this->store->readJson($file);
            } catch (\Throwable) {
                continue;
            }

            $code = $this->normalize(is_string($data['code'] ?? null) ? $data['code'] : basename($file, '.json'));
            if ($code === '') {
                continue;
            }

            $name = is_string($data['name'] ?? null) && trim($data['name']) !== ''
                ? trim($data['name'])
                : $this->nameFor($code);
            $languages[$code] = $name;
        }

        foreach ($this->usedLanguageCodes() as $code) {
            $languages[$code] ??= $this->nameFor($code);
        }

        $default = $this->normalize((string)($this->config['default_locale'] ?? 'es'));
        if ($default !== '') {
            $languages[$default] ??= $this->nameFor($default);
        }

        ksort($languages);
        return $languages;
    }

    public function defaultLanguage(): string
    {
        $settings = (new InstanceSettings($this->store, $this->config))->all();
        $configured = $this->normalize(is_string($settings['default_language'] ?? null) ? $settings['default_language'] : '');
        $available = $this->available();

        if ($configured !== '' && isset($available[$configured])) {
            return $configured;
        }

        $fallback = $this->normalize((string)($this->config['default_locale'] ?? 'es'));
        return $fallback !== '' && isset($available[$fallback]) ? $fallback : (array_key_first($available) ?? 'es');
    }

    public function validate(string $code): string
    {
        $code = $this->normalize($code);
        if ($code === '' || !isset($this->available()[$code])) {
            throw new \RuntimeException('Idioma no disponible en esta instancia.');
        }

        return $code;
    }

    public function normalize(string $code): string
    {
        $code = strtolower(str_replace('_', '-', trim($code)));
        return preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code) === 1 ? $code : '';
    }

    private function languageFiles(): array
    {
        $dir = dirname(__DIR__, 2) . '/lang';
        return glob($dir . '/*.json') ?: [];
    }

    private function usedLanguageCodes(): array
    {
        $codes = [];
        $settings = (new InstanceSettings($this->store, $this->config))->all();

        if (is_string($settings['default_language'] ?? null)) {
            $code = $this->normalize($settings['default_language']);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        foreach ((new LocalUsers($this->store, $this->config))->all() as $user) {
            if (!is_array($user) || !is_string($user['lang'] ?? null)) {
                continue;
            }

            $code = $this->normalize($user['lang']);
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    private function nameFor(string $code): string
    {
        return self::FALLBACK_NAMES[$code] ?? $code;
    }
}
