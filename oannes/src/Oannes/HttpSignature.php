<?php

namespace Oannes;

use RuntimeException;

final class HttpSignature
{
    /**
     * @return array<string, string>
     */
    public function signedPostHeaders(string $url, string $keyId, string $privateKeyPem, string $body): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '/';

        if (!is_string($host) || $host === '') {
            throw new RuntimeException('Cannot sign request without URL host');
        }

        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $digest = 'SHA-256=' . base64_encode(hash('sha256', $body, true));
        $target = 'post ' . $path;
        $headers = [
            '(request-target)' => $target,
            'host' => $host,
            'date' => $date,
            'digest' => $digest,
            'content-type' => 'application/activity+json',
        ];
        $signedHeaders = '(request-target) host date digest content-type';
        $signingString = $this->signingString($headers, explode(' ', $signedHeaders));

        $signature = '';
        $ok = openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            throw new RuntimeException('Cannot sign HTTP request');
        }

        return [
            'Host' => $host,
            'Date' => $date,
            'Digest' => $digest,
            'Content-Type' => 'application/activity+json',
            'Accept' => 'application/activity+json',
            'Signature' => 'keyId="' . $this->escape($keyId) . '",algorithm="rsa-sha256",headers="' . $signedHeaders . '",signature="' . base64_encode($signature) . '"',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function signedGetHeaders(string $url, string $keyId, string $privateKeyPem, string $accept = 'application/activity+json'): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '/';

        if (!is_string($host) || $host === '') {
            throw new RuntimeException('Cannot sign request without URL host');
        }

        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }

        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $target = 'get ' . $path;
        $headers = [
            '(request-target)' => $target,
            'host' => $host,
            'date' => $date,
        ];
        $signedHeaders = '(request-target) host date';
        $signingString = $this->signingString($headers, explode(' ', $signedHeaders));

        $signature = '';
        $ok = openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);

        if (!$ok) {
            throw new RuntimeException('Cannot sign HTTP request');
        }

        return [
            'Host' => $host,
            'Date' => $date,
            'Accept' => $accept,
            'Signature' => 'keyId="' . $this->escape($keyId) . '",algorithm="rsa-sha256",headers="' . $signedHeaders . '",signature="' . base64_encode($signature) . '"',
        ];
    }

    public function verifyDigest(array $headers, string $body): bool
    {
        $digest = $headers['digest'] ?? $headers['Digest'] ?? null;

        if (!is_string($digest)) {
            return false;
        }

        return hash_equals('SHA-256=' . base64_encode(hash('sha256', $body, true)), trim($digest));
    }

    public function verifyRequest(array $headers, string $method, string $requestTarget, string $publicKeyPem): bool
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $normalized[strtolower($name)] = $value;
            }
        }

        $signatureHeader = $normalized['signature'] ?? null;

        if (!is_string($signatureHeader)) {
            return false;
        }

        $params = $this->parseSignatureHeader($signatureHeader);
        $signature = $params['signature'] ?? null;

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        $algorithm = strtolower((string)($params['algorithm'] ?? 'rsa-sha256'));
        if ($algorithm !== 'rsa-sha256') {
            return false;
        }

        $headerNames = explode(' ', strtolower((string)($params['headers'] ?? 'date')));
        $signed = [];

        foreach ($headerNames as $name) {
            if ($name === '(request-target)') {
                $signed[$name] = strtolower($method) . ' ' . $requestTarget;
                continue;
            }

            if (!isset($normalized[$name])) {
                return false;
            }

            $signed[$name] = $normalized[$name];
        }

        $signingString = $this->signingString($signed, $headerNames);
        $decoded = base64_decode($signature, true);

        if ($decoded === false) {
            return false;
        }

        return openssl_verify($signingString, $decoded, $publicKeyPem, OPENSSL_ALGO_SHA256) === 1;
    }

    public function keyId(array $headers): ?string
    {
        $signatureHeader = $headers['signature'] ?? $headers['Signature'] ?? null;

        if (!is_string($signatureHeader)) {
            return null;
        }

        $params = $this->parseSignatureHeader($signatureHeader);
        $keyId = $params['keyId'] ?? null;

        return is_string($keyId) && $keyId !== '' ? $keyId : null;
    }

    public function signedHeaderNames(array $headers): array
    {
        $signatureHeader = $headers['signature'] ?? $headers['Signature'] ?? null;

        if (!is_string($signatureHeader)) {
            return [];
        }

        $params = $this->parseSignatureHeader($signatureHeader);
        $headerNames = strtolower((string)($params['headers'] ?? 'date'));

        return array_values(array_filter(explode(' ', $headerNames), static fn (string $name): bool => $name !== ''));
    }

    /**
     * @param array<string, string> $headers
     * @param list<string> $names
     */
    private function signingString(array $headers, array $names): string
    {
        $lines = [];

        foreach ($names as $name) {
            if (!array_key_exists($name, $headers)) {
                throw new RuntimeException("Missing signed header: {$name}");
            }

            $lines[] = $name . ': ' . $headers[$name];
        }

        return implode("\n", $lines);
    }

    private function escape(string $value): string
    {
        return addcslashes($value, "\\\"");
    }

    /**
     * @return array<string, string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $params = [];

        foreach (str_getcsv($header, ',', '"', '\\') as $part) {
            $pieces = explode('=', trim($part), 2);

            if (count($pieces) !== 2) {
                continue;
            }

            $name = trim($pieces[0]);
            $value = trim($pieces[1]);

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = stripcslashes(substr($value, 1, -1));
            }

            $params[$name] = $value;
        }

        return $params;
    }
}
