<?php

namespace Oannes;

use RuntimeException;

final class SnacImporter
{
    public function __construct(private readonly FileStore $store)
    {
    }

    public function analyse(string $snacDir): array
    {
        $objectFiles = $this->snacObjectFiles($snacDir);
        $objects = 0;
        $withReply = 0;
        $types = [];
        $badSnacChildLinks = [];
        $invalidFiles = [];

        foreach ($objectFiles as $file) {
            try {
                $object = Json::decodeFile($file);
            } catch (\Throwable $e) {
                $invalidFiles[$this->relativeTo($file, $snacDir)] = $e->getMessage();
                continue;
            }

            $id = ActivityPub::objectId($object);

            if ($id === null) {
                continue;
            }

            $objects++;
            $type = ActivityPub::objectType($object);
            $types[$type] = ($types[$type] ?? 0) + 1;

            if (ActivityPub::inReplyTo($object) !== null) {
                $withReply++;
            }
        }

        foreach ($this->snacChildIndexFiles($snacDir) as $idxFile) {
            try {
                $bad = $this->validateSnacChildIndex($idxFile, $snacDir);
            } catch (\Throwable $e) {
                $bad = [[
                    'error' => $e->getMessage(),
                ]];
            }

            if ($bad !== []) {
                $badSnacChildLinks[$this->relativeTo($idxFile, $snacDir)] = $bad;
            }
        }

        arsort($types);

        return [
            'objects' => $objects,
            'objects_with_inReplyTo' => $withReply,
            'types' => $types,
            'invalid_json_files' => $invalidFiles,
            'invalid_snac_child_indexes' => $badSnacChildLinks,
        ];
    }

    public function import(string $snacDir): array
    {
        $importedObjects = 0;
        $importedActors = 0;
        $skipped = 0;

        foreach ($this->snacObjectFiles($snacDir) as $file) {
            try {
                $object = Json::decodeFile($file);
                $id = ActivityPub::objectId($object);

                if ($id === null) {
                    $skipped++;
                    continue;
                }

                if (ActivityPub::isActor($object)) {
                    $this->store->writeActor($object);
                    $importedActors++;
                } else {
                    $this->store->writeObject($object);
                    $importedObjects++;
                }
            } catch (\Throwable) {
                $skipped++;
            }
        }

        foreach (glob(rtrim($snacDir, '/') . '/user/*/user.json') ?: [] as $userFile) {
            $user = Json::decodeFile($userFile);
            $uid = $user['uid'] ?? basename(dirname($userFile));
            $this->store->writeJson($this->store->dataDir() . '/actors/local/' . $uid . '.json', $user);
        }

        $authStats = $this->importLocalPasswords($snacDir);
        $keyStats = $this->importLocalKeys($snacDir);
        $graphStats = $this->importSocialGraph($snacDir);

        $indexStats = (new IndexBuilder($this->store))->rebuild();

        return [
            'imported_objects' => $importedObjects,
            'imported_actors' => $importedActors,
            'skipped' => $skipped,
            'local_passwords' => $authStats,
            'local_keys' => $keyStats,
            'social_graph' => $graphStats,
            'index' => $indexStats,
        ];
    }

    private function importLocalPasswords(string $snacDir): array
    {
        $auth = new Auth($this->store);
        $stats = [
            'imported' => 0,
        ];

        foreach (glob(rtrim($snacDir, '/') . '/user/*/user.json') ?: [] as $userFile) {
            try {
                $user = Json::decodeFile($userFile);
                $uid = $user['uid'] ?? basename(dirname($userFile));
                $passwd = $user['passwd'] ?? null;

                if (is_string($uid) && is_string($passwd)) {
                    $auth->importSnacPassword($uid, $passwd);
                    $stats['imported']++;
                }
            } catch (\Throwable) {
            }
        }

        return $stats;
    }

    private function importLocalKeys(string $snacDir): array
    {
        $keys = new KeyStore($this->store);
        $stats = [
            'imported' => 0,
        ];

        foreach (glob(rtrim($snacDir, '/') . '/user/*/key.json') ?: [] as $keyFile) {
            try {
                $uid = basename(dirname($keyFile));
                $keys->importLocal($uid, Json::decodeFile($keyFile));
                $stats['imported']++;
            } catch (\Throwable) {
            }
        }

        return $stats;
    }

    private function importSocialGraph(string $snacDir): array
    {
        $graph = new SocialGraph($this->store);
        $stats = [
            'followers' => 0,
            'following' => 0,
        ];

        foreach (glob(rtrim($snacDir, '/') . '/user/*', GLOB_ONLYDIR) ?: [] as $userDir) {
            $uid = basename($userDir);

            foreach (glob($userDir . '/followers/*.json') ?: [] as $file) {
                try {
                    $actor = Json::decodeFile($file);
                    $graph->addFollower($uid, $actor);
                    $stats['followers']++;
                } catch (\Throwable) {
                }
            }

            foreach (glob($userDir . '/following/*.json') ?: [] as $file) {
                try {
                    $actor = Json::decodeFile($file);
                    $graph->addFollowing($uid, $actor);
                    $stats['following']++;
                } catch (\Throwable) {
                }
            }
        }

        return $stats;
    }

    private function validateSnacChildIndex(string $idxFile, string $snacDir): array
    {
        $parentHash = preg_replace('/_c\\.idx$/', '', basename($idxFile));
        $parentFile = dirname($idxFile) . '/' . $parentHash . '.json';

        if (!is_file($parentFile)) {
            return [];
        }

        $parent = Json::decodeFile($parentFile);
        $parentIds = ActivityPub::aliases($parent);
        $bad = [];
        $children = file($idxFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($children as $childHash) {
            $childFile = $this->findSnacObjectByHash($snacDir, trim($childHash));
            if ($childFile === null) {
                continue;
            }

            $child = Json::decodeFile($childFile);
            $replyTo = ActivityPub::inReplyTo($child);

            if ($replyTo === null || !in_array($replyTo, $parentIds, true)) {
                $bad[] = [
                    'child_hash' => trim($childHash),
                    'child_id' => ActivityPub::objectId($child),
                    'child_inReplyTo' => $replyTo,
                    'parent_ids' => $parentIds,
                ];
            }
        }

        return $bad;
    }

    private function findSnacObjectByHash(string $snacDir, string $hash): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
            return null;
        }

        $path = rtrim($snacDir, '/') . '/object/' . substr($hash, 0, 2) . '/' . $hash . '.json';
        return is_file($path) ? $path : null;
    }

    private function snacObjectFiles(string $snacDir): iterable
    {
        $root = rtrim($snacDir, '/') . '/object';

        if (!is_dir($root)) {
            throw new RuntimeException("Snac object directory not found: {$root}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.json')) {
                yield $file->getPathname();
            }
        }
    }

    private function snacChildIndexFiles(string $snacDir): iterable
    {
        $root = rtrim($snacDir, '/') . '/object';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '_c.idx')) {
                yield $file->getPathname();
            }
        }
    }

    private function relativeTo(string $path, string $root): string
    {
        $root = rtrim($root, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
