<?php

namespace Oannes;

final class ProfileImageService
{
    public function __construct(private readonly array $config)
    {
    }

    public function saveFromPost(string $uid, string $field, int $width, int $height): ?string
    {
        $data = $_POST[$field . '_image'] ?? null;
        $binary = null;

        if (is_string($data) && preg_match('#^data:image/(?:png|jpeg|webp);base64,#', $data)) {
            $encoded = preg_replace('#^data:image/[^;]+;base64,#', '', $data) ?? '';
            $binary = base64_decode($encoded, true);
        }

        if ($binary === null || $binary === false || $binary === '') {
            $upload = $_FILES[$field . '_upload'] ?? null;

            if (is_array($upload) && (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $tmp = $upload['tmp_name'] ?? null;
                if (is_string($tmp) && is_uploaded_file($tmp)) {
                    $binary = file_get_contents($tmp);
                }
            }
        }

        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (!$source instanceof \GdImage) {
            throw new \RuntimeException('La imagen no se pudo leer.');
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $targetRatio = $width / $height;
        $sourceRatio = $srcW / max(1, $srcH);

        if ($sourceRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int)round($srcH * $targetRatio);
            $cropX = (int)floor(($srcW - $cropW) / 2);
            $cropY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int)round($srcW / $targetRatio);
            $cropX = 0;
            $cropY = (int)floor(($srcH - $cropH) / 2);
        }

        $target = imagecreatetruecolor($width, $height);
        if (!$target instanceof \GdImage) {
            throw new \RuntimeException('No se pudo crear la imagen destino.');
        }

        imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $width, $height, $cropW, $cropH);

        $dir = rtrim((string)$this->config['data_dir'], '/') . '/media/' . $uid;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('No se pudo crear el directorio de imágenes.');
        }

        @chmod($dir, 02775);

        $name = $field . '-oannes-' . bin2hex(random_bytes(8)) . '.jpg';
        $path = $dir . '/' . $name;

        if (!imagejpeg($target, $path, 88)) {
            throw new \RuntimeException('No se pudo guardar la imagen.');
        }

        @chmod($path, 0664);

        imagedestroy($source);
        imagedestroy($target);

        return rtrim((string)$this->config['base_url'], '/') . '/' . rawurlencode($uid) . '/s/' . rawurlencode($name);
    }
}
