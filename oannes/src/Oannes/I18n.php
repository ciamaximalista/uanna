<?php

namespace Oannes;

final class I18n
{
    private ?string $language = null;
    private ?array $translations = null;
    private ?array $fallbackTranslations = null;

    public function __construct(
        private readonly FileStore $store,
        private readonly array $config,
        private readonly ?string $overrideLanguage = null,
    ) {
    }

    public function language(): string
    {
        if ($this->language !== null) {
            return $this->language;
        }

        $languages = array_keys((new LanguageCatalog($this->store, $this->config))->available());

        if ($this->overrideLanguage !== null && in_array($this->overrideLanguage, $languages, true)) {
            return $this->language = $this->overrideLanguage;
        }

        $uid = (new Auth($this->store))->currentUser();
        if ($uid !== null) {
            $user = (new LocalUsers($this->store, $this->config))->find($uid);
            $lang = is_array($user) && is_string($user['lang'] ?? null) ? $user['lang'] : '';
            if (in_array($lang, $languages, true)) {
                return $this->language = $lang;
            }
        }

        $default = (new InstanceSettings($this->store, $this->config))->defaultLanguage();
        return $this->language = in_array($default, $languages, true) ? $default : 'es';
    }

    public function t(string $key, string $fallback = '', array $params = []): string
    {
        $text = $this->translations()[$key]
            ?? $this->fallbackTranslations()[$key]
            ?? ($fallback !== '' ? $fallback : $key);

        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', (string)$value, $text);
        }

        return $text;
    }

    private function translations(): array
    {
        if ($this->translations === null) {
            $this->translations = $this->translationsFor($this->language());
        }

        return $this->translations;
    }

    private function fallbackTranslations(): array
    {
        if ($this->fallbackTranslations === null) {
            $this->fallbackTranslations = $this->translationsFor('es');
        }

        return $this->fallbackTranslations;
    }

    private function translationsFor(string $code): array
    {
        $path = dirname(__DIR__, 2) . '/lang/' . rawurlencode($code) . '.json';
        if (!is_file($path)) {
            return [];
        }

        try {
            $data = Json::decodeFile($path);
        } catch (\Throwable) {
            return [];
        }

        return is_array($data['translations'] ?? null) ? $data['translations'] : [];
    }
}
