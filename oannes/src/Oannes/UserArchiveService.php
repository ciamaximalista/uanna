<?php

namespace Oannes;

use DOMDocument;
use DOMElement;
use RuntimeException;
use ZipArchive;

final class UserArchiveService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly array $config,
    ) {
    }

    public function exportXml(string $uid): string
    {
        return $this->buildXml($uid);
    }

    public function exportZip(string $uid): string
    {
        $mediaFiles = [];
        $xml = $this->buildXml($uid, $mediaFiles);
        $path = $this->store->dataDir() . '/tmp/uanna-export-' . rawurlencode($uid) . '-' . bin2hex(random_bytes(8)) . '.zip';
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el archivo ZIP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo ZIP.');
        }

        $zip->addFromString('archive.xml', $xml);
        foreach ($mediaFiles as $archivePath => $localPath) {
            if (is_string($archivePath) && is_string($localPath) && is_file($localPath)) {
                $zip->addFile($localPath, $archivePath);
            }
        }

        $zip->close();

        return $path;
    }

    private function buildXml(string $uid, array &$mediaFiles = []): string
    {
        $user = $this->users->find($uid);
        if ($user === null) {
            throw new RuntimeException('Usuario local no encontrado.');
        }

        $objects = $this->localUserObjects($uid);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;
        $root = $doc->createElement('uanna-user-archive');
        $root->setAttribute('version', '1');
        $root->setAttribute('exported_at', gmdate('c'));
        $root->setAttribute('base_url', rtrim((string)$this->config['base_url'], '/'));
        $doc->appendChild($root);

        $userNode = $doc->createElement('user');
        $userNode->setAttribute('uid', $uid);
        foreach (['name', 'bio', 'avatar', 'header', 'email', 'lang', 'tz'] as $field) {
            $this->appendText($doc, $userNode, $field, (string)($user[$field] ?? ''));
        }
        $root->appendChild($userNode);

        $objectsNode = $doc->createElement('objects');
        $objectsNode->setAttribute('count', (string)count($objects));
        foreach ($objects as $object) {
            $object = $this->prepareObjectForArchive($uid, $object, $mediaFiles);
            $objectNode = $doc->createElement('object');
            $objectNode->setAttribute('id', ActivityPub::objectId($object) ?? '');
            $objectNode->setAttribute('type', ActivityPub::objectType($object));
            $objectNode->setAttribute('published', ActivityPub::published($object));
            $json = $doc->createElement('json');
            $json->appendChild($doc->createCDATASection(Json::encode($object)));
            $objectNode->appendChild($json);
            $objectsNode->appendChild($objectNode);
        }
        $root->appendChild($objectsNode);

        return $doc->saveXML() ?: '';
    }

    public function importArchive(string $pathOrXml, string $password = '', bool $isPath = false): array
    {
        if ($isPath && $this->looksLikeZip($pathOrXml)) {
            return $this->importZip($pathOrXml, $password);
        }

        $xml = $isPath ? (file_get_contents($pathOrXml) ?: '') : $pathOrXml;
        return $this->importXml($xml, $password);
    }

    public function importZip(string $path, string $password = ''): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('No se pudo abrir el ZIP.');
        }

        $xml = $zip->getFromName('archive.xml');
        if (!is_string($xml) || $xml === '') {
            $zip->close();
            throw new RuntimeException('El ZIP no contiene archive.xml.');
        }

        $mediaMap = [];
        $tmpDir = $this->store->dataDir() . '/tmp/import-' . bin2hex(random_bytes(8));
        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
            $zip->close();
            throw new RuntimeException('No se pudo preparar la importación.');
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!is_string($name) || !str_starts_with($name, 'media/') || str_ends_with($name, '/')) {
                continue;
            }

            $safeName = $this->safeArchivePath($name);
            if ($safeName === null) {
                continue;
            }

            $target = $tmpDir . '/' . basename($safeName);
            $bytes = $zip->getFromIndex($i);
            if (is_string($bytes)) {
                file_put_contents($target, $bytes);
                $mediaMap[$safeName] = $target;
            }
        }

        $zip->close();

        try {
            return $this->importXml($xml, $password, $mediaMap);
        } finally {
            $this->deleteTree($tmpDir);
        }
    }

    public function importXml(string $xml, string $password = '', array $mediaMap = []): array
    {
        $doc = new DOMDocument();
        if (@$doc->loadXML($xml) === false) {
            throw new RuntimeException('XML no válido.');
        }

        $root = $doc->documentElement;
        if (!$root instanceof DOMElement || $root->tagName !== 'uanna-user-archive') {
            throw new RuntimeException('El XML no es un archivo de usuario de Uanna.');
        }

        $userNode = $this->firstElement($root, 'user');
        if ($userNode === null) {
            throw new RuntimeException('El XML no contiene usuario.');
        }

        $uid = trim($userNode->getAttribute('uid'));
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new RuntimeException('El usuario del XML no es válido.');
        }

        $existing = $this->users->find($uid);
        if ($existing === null && $password === '') {
            throw new RuntimeException('Indica una clave inicial para importar un usuario nuevo.');
        }

        if ($existing === null) {
            $this->users->create($uid, $this->textValue($userNode, 'name'));
            (new Auth($this->store))->setPassword($uid, $password);
        }

        $fields = [];
        foreach (['name', 'bio', 'avatar', 'header', 'email', 'lang', 'tz'] as $field) {
            $value = $this->textValue($userNode, $field);
            if ($value !== '') {
                $fields[$field] = $value;
            }
        }
        if ($fields !== []) {
            $this->users->updateProfile($uid, $fields);
        }

        $actorIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
        $localActorId = $this->users->actorId($uid);
        $imported = 0;
        $objectsNode = $this->firstElement($root, 'objects');
        if ($objectsNode !== null) {
            foreach ($objectsNode->getElementsByTagName('object') as $objectNode) {
                if (!$objectNode instanceof DOMElement) {
                    continue;
                }

                $jsonNode = $this->firstElement($objectNode, 'json');
                if ($jsonNode === null) {
                    continue;
                }

                $object = Json::decode((string)$jsonNode->textContent, 'archivo de usuario');
                $actor = ActivityPub::attributedTo($object);
                if ($actor === null || (!$this->actorBelongsToUid($actor, $uid) && !in_array($actor, $actorIds, true))) {
                    throw new RuntimeException('El XML contiene posts atribuidos a otro actor.');
                }

                $object['attributedTo'] = $localActorId;
                $object = $this->restoreObjectMedia($uid, $object, $mediaMap);
                $this->store->writeObject($object);
                $imported++;
            }
        }

        (new IndexBuilder($this->store))->rebuild();

        return [
            'uid' => $uid,
            'objects' => $imported,
            'media' => count($mediaMap),
        ];
    }

    public function deleteUserContent(string $uid): int
    {
        $deleted = 0;
        foreach ($this->localUserObjects($uid) as $object) {
            $id = ActivityPub::objectId($object);
            if ($id === null) {
                continue;
            }

            $this->store->deleteObject($id);
            $deleted++;
        }

        (new IndexBuilder($this->store))->rebuild();
        return $deleted;
    }

    public function deleteUserAndContent(string $uid): int
    {
        $deleted = $this->deleteUserContent($uid);
        $this->users->delete($uid);
        (new Auth($this->store))->deleteUser($uid);
        $this->deleteTree($this->store->dataDir() . '/social/' . rawurlencode($uid));
        $this->deleteLocalActorReferences($uid);

        return $deleted;
    }

    private function localUserObjects(string $uid): array
    {
        $actorIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
        $objects = [];

        foreach ($this->store->objectFiles() as $file) {
            $object = $this->store->readJson($file);
            $actor = ActivityPub::attributedTo($object);
            if ($actor !== null && in_array($actor, $actorIds, true)) {
                $objects[] = $object;
            }
        }

        usort($objects, static fn (array $a, array $b): int => strcmp(
            ActivityPub::published($a),
            ActivityPub::published($b)
        ));

        return $objects;
    }

    private function prepareObjectForArchive(string $uid, array $object, array &$mediaFiles): array
    {
        $attachments = $object['attachment'] ?? null;
        if (!is_array($attachments)) {
            return $object;
        }

        $object['attachment'] = $this->mapAttachments($attachments, function (array $attachment) use ($uid, &$mediaFiles): array {
            $url = is_string($attachment['url'] ?? null) ? $attachment['url'] : '';
            $localPath = $this->localMediaPath($uid, $url);
            if ($localPath === null) {
                return $attachment;
            }

            $archivePath = 'media/' . basename($localPath);
            $mediaFiles[$archivePath] = $localPath;
            $attachment['url'] = $archivePath;
            $attachment['_uanna_original_url'] = $url;

            return $attachment;
        });

        return $object;
    }

    private function restoreObjectMedia(string $uid, array $object, array $mediaMap): array
    {
        $attachments = $object['attachment'] ?? null;
        if (!is_array($attachments)) {
            return $object;
        }

        $object['attachment'] = $this->mapAttachments($attachments, function (array $attachment) use ($uid, $mediaMap): array {
            $url = is_string($attachment['url'] ?? null) ? $attachment['url'] : '';
            if ($url === '' || !isset($mediaMap[$url]) || !is_file($mediaMap[$url])) {
                unset($attachment['_uanna_original_url']);
                return $attachment;
            }

            $attachment['url'] = $this->storeImportedMedia($uid, $mediaMap[$url], (string)($attachment['mediaType'] ?? 'application/octet-stream'));
            unset($attachment['_uanna_original_url']);

            return $attachment;
        });

        return $object;
    }

    private function mapAttachments(array $attachments, callable $mapper): array
    {
        $mapped = [];
        foreach ($attachments as $key => $attachment) {
            if (is_array($attachment)) {
                $mapped[$key] = $mapper($attachment);
            } else {
                $mapped[$key] = $attachment;
            }
        }

        return $mapped;
    }

    private function localMediaPath(string $uid, string $url): ?string
    {
        $base = rtrim((string)$this->config['base_url'], '/');
        if ($url === '' || !str_starts_with($url, $base . '/')) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !preg_match('#^/' . preg_quote($uid, '#') . '/s/([^/]+)$#', $path, $match)) {
            return null;
        }

        $fileName = rawurldecode($match[1]);
        if ($fileName === '' || basename($fileName) !== $fileName) {
            return null;
        }

        $root = rtrim((string)($this->config['root_dir'] ?? dirname(__DIR__, 3)), '/');
        $localPath = $root . '/user/' . $uid . '/static/' . $fileName;

        return is_file($localPath) ? $localPath : null;
    }

    private function storeImportedMedia(string $uid, string $path, string $mediaType): string
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('No se pudo restaurar un adjunto.');
        }

        $extension = $this->extensionForMediaType($mediaType, $path);
        $fileName = 'media-uanna-' . substr(hash('sha256', $bytes), 0, 24) . '.' . $extension;
        $root = rtrim((string)($this->config['root_dir'] ?? dirname(__DIR__, 3)), '/');
        $dir = $root . '/user/' . $uid . '/static';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo preparar el directorio de adjuntos.');
        }

        $target = $dir . '/' . $fileName;
        if (!is_file($target) && !copy($path, $target)) {
            throw new RuntimeException('No se pudo copiar un adjunto importado.');
        }

        return rtrim((string)$this->config['base_url'], '/') . '/' . rawurlencode($uid) . '/s/' . rawurlencode($fileName);
    }

    private function extensionForMediaType(string $mediaType, string $path): string
    {
        return match ($mediaType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            default => strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'bin'),
        };
    }

    private function looksLikeZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $bytes = fread($handle, 4);
        fclose($handle);

        return $bytes === "PK\x03\x04";
    }

    private function safeArchivePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }

    private function deleteLocalActorReferences(string $uid): void
    {
        $ids = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));

        foreach (glob($this->store->dataDir() . '/social/*/{followers,following}/*.json', GLOB_BRACE) ?: [] as $file) {
            try {
                $actor = $this->store->readJson($file);
            } catch (\Throwable) {
                continue;
            }

            foreach ($ids as $id) {
                if (in_array($id, ActivityPub::aliases($actor), true)) {
                    unlink($file);
                    break;
                }
            }
        }
    }

    private function appendText(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $node = $doc->createElement($name);
        $node->appendChild($doc->createTextNode($value));
        $parent->appendChild($node);
    }

    private function actorBelongsToUid(string $actor, string $uid): bool
    {
        $path = parse_url($actor, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        return preg_match('#(^|/)(u/)?' . preg_quote($uid, '#') . '$#', trim($path, '/')) === 1;
    }

    private function firstElement(DOMElement $parent, string $tag): ?DOMElement
    {
        foreach ($parent->getElementsByTagName($tag) as $node) {
            if ($node instanceof DOMElement && $node->parentNode === $parent) {
                return $node;
            }
        }

        return null;
    }

    private function textValue(DOMElement $parent, string $tag): string
    {
        $node = $this->firstElement($parent, $tag);
        return $node instanceof DOMElement ? trim((string)$node->textContent) : '';
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
