<?php

namespace Oannes;

final class Http
{
    public static function wantsActivityJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        if (is_string($userAgent) && str_contains($userAgent, 'Nammu Fediverso')) {
            return true;
        }

        return is_string($accept)
            && (
                str_contains($accept, 'application/activity+json')
                || str_contains($accept, 'application/ld+json')
                || str_contains($accept, 'application/json')
            );
    }

    public static function json(array $data, string $contentType = 'application/json', int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType . '; charset=utf-8');
        echo Json::encode($data);
    }

    public static function activityJson(array $data, int $status = 200): void
    {
        header('Cache-Control: public, max-age=300');
        self::json($data, 'application/activity+json', $status);
    }

    public static function cachedActivityJson(string $json, int $maxAge = 300): void
    {
        http_response_code(200);
        header('Cache-Control: public, max-age=' . $maxAge);
        header('Content-Type: application/activity+json; charset=utf-8');
        echo $json;
    }

    public static function notFound(): void
    {
        self::json(['error' => 'not_found'], 'application/json', 404);
    }

    public static function gone(array $data = []): void
    {
        header('Retry-After: 86400');
        self::json($data !== [] ? $data : ['error' => 'gone'], 'application/activity+json', 410);
    }

    public static function tooManyRequests(int $retryAfter = 60): void
    {
        header('Retry-After: ' . $retryAfter);
        self::json(['error' => 'too_many_requests'], 'application/json', 429);
    }

    public static function methodNotAllowed(): void
    {
        self::json(['error' => 'method_not_allowed'], 'application/json', 405);
    }

    public static function requestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $name = $key;
            } else {
                continue;
            }

            $name = strtolower(str_replace('_', '-', $name));
            $headers[$name] = $value;
        }

        return $headers;
    }

    public static function requestTarget(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        return is_string($uri) && $uri !== '' ? $uri : '/';
    }
}
