<?php

namespace Oannes;

final class MediaUploadService
{
    public function __construct(private readonly array $config)
    {
    }

    public function saveImageFromPost(string $uid, string $field, string $alt): ?array
    {
        $upload = $_FILES[$field] ?? null;

        if (!is_array($upload) || (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        $error = (int)($upload['error'] ?? UPLOAD_ERR_OK);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage($error));
        }

        $size = (int)($upload['size'] ?? 0);
        $maxBytes = (int)($this->config['max_attachment_bytes'] ?? 26214400);
        if ($size <= 0 || $size > $maxBytes) {
            throw new \RuntimeException('La imagen adjunta supera el tamaño permitido.');
        }

        $tmp = (string)($upload['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('La imagen adjunta no es válida.');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false || $bytes === '') {
            throw new \RuntimeException('La imagen adjunta está vacía.');
        }

        $image = @imagecreatefromstring($bytes);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('La imagen adjunta debe ser PNG, JPEG, GIF o WebP.');
        }

        $mediaType = mime_content_type($tmp) ?: 'image/jpeg';
        if (!in_array($mediaType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            $mediaType = 'image/jpeg';
        }

        $extension = match ($mediaType) {
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $fileName = 'media-oannes-' . substr(hash('sha256', $bytes), 0, 24) . '.' . $extension;
        $dir = rtrim((string)$this->config['data_dir'], '/') . '/media/' . $uid;

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo preparar el directorio de adjuntos.');
        }

        @chmod($dir, 02775);

        if (!is_writable($dir)) {
            throw new \RuntimeException('No se puede escribir en el directorio de adjuntos. Revisa permisos de oannes/data/media/' . $uid . '.');
        }

        $target = $dir . '/' . $fileName;
        if (!move_uploaded_file($tmp, $target)) {
            throw new \RuntimeException('No se pudo guardar la imagen adjunta.');
        }

        @chmod($target, 0664);

        return [
            'type' => 'Document',
            'mediaType' => $mediaType,
            'url' => rtrim((string)$this->config['base_url'], '/') . '/' . rawurlencode($uid) . '/s/' . rawurlencode($fileName),
            'name' => trim($alt),
            'summary' => trim($alt),
        ];
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE => 'La imagen supera el límite de subida configurado en PHP (' . ini_get('upload_max_filesize') . ').',
            UPLOAD_ERR_FORM_SIZE => 'La imagen supera el límite permitido por el formulario.',
            UPLOAD_ERR_PARTIAL => 'La imagen se subió sólo parcialmente. Inténtalo de nuevo.',
            UPLOAD_ERR_NO_TMP_DIR => 'PHP no tiene directorio temporal para subidas.',
            UPLOAD_ERR_CANT_WRITE => 'PHP no pudo escribir la subida temporal en disco.',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP bloqueó la subida.',
            default => 'No se pudo subir la imagen adjunta.',
        };
    }
}
