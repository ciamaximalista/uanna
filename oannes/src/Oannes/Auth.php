<?php

namespace Oannes;

final class Auth
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $sessionDir = $this->store->dataDir() . '/sessions';

            if (!is_dir($sessionDir)) {
                mkdir($sessionDir, 0775, true);
            }

            session_save_path($sessionDir);
            session_name('OANNESSESSID');
            session_start();
        }
    }

    public function currentUser(): ?string
    {
        $this->start();
        $uid = $_SESSION['uid'] ?? null;
        return is_string($uid) && $uid !== '' ? $uid : null;
    }

    public function csrfToken(): string
    {
        $this->start();

        if (!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(24));
        }

        return $_SESSION['csrf'];
    }

    public function checkCsrf(?string $token): bool
    {
        $this->start();
        return is_string($token)
            && isset($_SESSION['csrf'])
            && is_string($_SESSION['csrf'])
            && hash_equals($_SESSION['csrf'], $token);
    }

    public function login(string $uid, string $password): bool
    {
        if (!$this->verifyPassword($uid, $password)) {
            return false;
        }

        $this->start();
        session_regenerate_id(true);
        $_SESSION['uid'] = $uid;
        $_SESSION['csrf'] = bin2hex(random_bytes(24));

        return true;
    }

    public function verifyPassword(string $uid, string $password): bool
    {
        $record = $this->readUserAuth($uid);

        if ($record === null) {
            return false;
        }

        $hash = $record['password_hash'] ?? null;
        $legacyHash = $record['snac_passwd'] ?? null;

        return (is_string($hash) && password_verify($password, $hash))
            || (is_string($legacyHash) && $this->verifySnacPassword($uid, $password, $legacyHash));
    }

    public function logout(): void
    {
        $this->start();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function setPassword(string $uid, string $password): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new \InvalidArgumentException('Invalid local user id');
        }

        $record = $this->readUserAuth($uid) ?? ['uid' => $uid];
        $record['uid'] = $uid;
        $record['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        $record['updated_at'] = gmdate('c');

        $this->store->writeJson($this->path($uid), $record);
    }

    public function deleteUser(string $uid): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new \InvalidArgumentException('Invalid local user id');
        }

        $path = $this->path($uid);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function importSnacPassword(string $uid, string $snacPasswd): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new \InvalidArgumentException('Invalid local user id');
        }

        if (!preg_match('/^[a-f0-9]{8}:[a-f0-9]{40}$/i', $snacPasswd)) {
            return;
        }

        $record = $this->readUserAuth($uid) ?? [
            'uid' => $uid,
        ];
        $record['snac_passwd'] = strtolower($snacPasswd);
        $record['snac_imported_at'] = gmdate('c');

        $this->store->writeJson($this->path($uid), $record);
    }

    public function auditLocalUsers(array $uids): array
    {
        $users = [];
        $missing = [];

        foreach ($uids as $uid) {
            if (!is_string($uid) || $uid === '') {
                continue;
            }

            $record = $this->readUserAuth($uid);
            $hasPassword = is_array($record)
                && (
                    isset($record['password_hash']) && is_string($record['password_hash'])
                    || isset($record['snac_passwd']) && is_string($record['snac_passwd'])
                );

            $users[$uid] = [
                'has_oannes_password' => is_array($record) && isset($record['password_hash']) && is_string($record['password_hash']),
                'has_snac_password' => is_array($record) && isset($record['snac_passwd']) && is_string($record['snac_passwd']),
                'can_login' => $hasPassword,
            ];

            if (!$hasPassword) {
                $missing[] = $uid;
            }
        }

        ksort($users);

        return [
            'ok' => $missing === [],
            'users' => $users,
            'missing' => $missing,
        ];
    }

    private function readUserAuth(string $uid): ?array
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            return null;
        }

        $path = $this->path($uid);
        return is_file($path) ? $this->store->readJson($path) : null;
    }

    private function path(string $uid): string
    {
        return $this->store->dataDir() . '/auth/users/' . $uid . '.json';
    }

    private function verifySnacPassword(string $uid, string $password, string $snacPasswd): bool
    {
        [$salt, $hash] = explode(':', strtolower($snacPasswd), 2);
        return hash_equals($hash, sha1($salt . ':' . $uid . ':' . $password));
    }
}
