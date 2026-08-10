<?php

namespace Oannes;

use RuntimeException;

final class FileStore
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function dataDir(): string
    {
        return $this->dataDir;
    }

    public function writeJson(string $path, array $data): void
    {
        $this->writeAtomic($path, Json::encode($data));
    }

    public function writeText(string $path, string $content): void
    {
        $this->writeAtomic($path, $content);
    }

    public function readJson(string $path): array
    {
        return Json::decodeFile($path);
    }

    public function writeObject(array $object): string
    {
        $id = ActivityPub::objectId($object);

        if ($id === null) {
            throw new RuntimeException('Cannot store ActivityPub object without id');
        }

        $path = Id::objectPath($this->dataDir, $id);
        $this->writeJson($path, $object);

        return $path;
    }

    public function deleteObject(string $id): void
    {
        $path = Id::objectPath($this->dataDir, $id);

        if (is_file($path)) {
            unlink($path);
        }
    }

    public function writeActor(array $actor): string
    {
        $id = ActivityPub::objectId($actor);

        if ($id === null) {
            throw new RuntimeException('Cannot store actor without id');
        }

        $path = Id::actorPath($this->dataDir, $id);
        $this->writeJson($path, $actor);

        return $path;
    }

    public function objectFiles(): iterable
    {
        $root = $this->dataDir . '/objects';

        if (!is_dir($root)) {
            return;
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

    public function actorFiles(): iterable
    {
        $root = $this->dataDir . '/actors';

        if (!is_dir($root)) {
            return;
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

    private function writeAtomic(string $path, string $content): void
    {
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create directory: {$dir}");
        }

        @chmod($dir, 02775);

        if (!is_writable($dir)) {
            throw new RuntimeException("No write permission in target directory: {$dir}. Check that PHP and CLI use the same writable group.");
        }

        if (is_file($path) && !is_writable($path)) {
            throw new RuntimeException("No write permission for target file: {$path}. Check file owner and group permissions.");
        }

        $tmp = $dir . '/.write-' . bin2hex(random_bytes(12)) . '.tmp';
        $bytes = file_put_contents($tmp, $content, LOCK_EX);

        if ($bytes === false || $bytes !== strlen($content)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot write temporary file: {$tmp}");
        }

        @chmod($tmp, 0664);

        $handle = fopen($tmp, 'rb');
        if ($handle === false) {
            @unlink($tmp);
            throw new RuntimeException("Cannot reopen temporary file: {$tmp}");
        }

        fflush($handle);
        fclose($handle);

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException("Cannot move {$tmp} to {$path}");
        }

        @chmod($path, 0664);
    }
}
