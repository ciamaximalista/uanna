<?php

namespace Oannes;

use RuntimeException;

final class Json
{
    public static function decodeFile(string $path): array
    {
        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Cannot read JSON file: {$path}");
        }

        return self::decode($raw, $path);
    }

    public static function decode(string $raw, string $label = 'JSON'): array
    {
        $data = json_decode($raw, true);

        if (!is_array($data)) {
            throw new RuntimeException("Invalid {$label}: " . json_last_error_msg());
        }

        return $data;
    }

    public static function encode(array $data): string
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (!is_string($json)) {
            throw new RuntimeException('Cannot encode JSON: ' . json_last_error_msg());
        }

        return $json . "\n";
    }
}

