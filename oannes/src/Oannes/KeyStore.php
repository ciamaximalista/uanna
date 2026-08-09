<?php

namespace Oannes;

final class KeyStore
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function importLocal(string $uid, array $keys): void
    {
        $public = $keys['public'] ?? null;
        $secret = $keys['secret'] ?? null;

        if (!is_string($public) || trim($public) === '') {
            return;
        }

        $this->store->writeJson($this->path($uid), [
            'uid' => $uid,
            'public' => $public,
            'secret' => is_string($secret) ? $secret : null,
            'imported_at' => gmdate('c'),
        ]);
    }

    public function ensure(string $uid): void
    {
        if ($this->publicKey($uid) !== null) {
            return;
        }

        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false) {
            throw new \RuntimeException('No se pudo crear la clave del usuario.');
        }

        $secret = '';
        openssl_pkey_export($key, $secret);
        $details = openssl_pkey_get_details($key);
        $public = is_array($details) && is_string($details['key'] ?? null) ? $details['key'] : '';

        $this->importLocal($uid, [
            'public' => $public,
            'secret' => $secret,
        ]);
    }

    public function publicKey(string $uid): ?string
    {
        $keys = $this->read($uid);
        $public = $keys['public'] ?? null;

        return is_string($public) && $public !== '' ? $public : null;
    }

    public function secretKey(string $uid): ?string
    {
        $keys = $this->read($uid);
        $secret = $keys['secret'] ?? null;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    private function read(string $uid): array
    {
        $path = $this->path($uid);

        return is_file($path) ? $this->store->readJson($path) : [];
    }

    private function path(string $uid): string
    {
        return $this->store->dataDir() . '/keys/local/' . rawurlencode($uid) . '.json';
    }
}
