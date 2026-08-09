<?php

namespace Oannes;

final class InstanceAssetService
{
    public function __construct(private readonly array $config)
    {
    }

    public function saveImageFromPost(string $field): ?string
    {
        $upload = $_FILES[$field] ?? null;

        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ((int)($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('No se pudo subir la imagen.');
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('Imagen no válida.');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false || @imagecreatefromstring($bytes) === false) {
            throw new \RuntimeException('El archivo debe ser una imagen.');
        }

        $mediaType = mime_content_type($tmp) ?: 'image/png';
        $extension = match ($mediaType) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
        $root = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/');
        $dir = $root . '/assets/instance';

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de assets.');
        }

        $name = $field . '-' . substr(hash('sha256', $bytes), 0, 16) . '.' . $extension;
        if (!move_uploaded_file($tmp, $dir . '/' . $name)) {
            throw new \RuntimeException('No se pudo guardar la imagen.');
        }

        return '/assets/instance/' . $name;
    }
}
