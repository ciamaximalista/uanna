<?php

namespace Oannes;

use RuntimeException;

final class DeliveryWorker
{
    public function __construct(
        private readonly FileStore $store,
        private readonly FileQueue $queue,
        private readonly KeyStore $keys,
        private readonly array $config,
    ) {
    }

    public function run(int $limit = 25, bool $dryRun = false): array
    {
        $stats = [
            'checked' => 0,
            'delivered' => 0,
            'failed' => 0,
            'dead' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
            'delivery_enabled' => (bool)($this->config['delivery_enabled'] ?? false),
        ];

        $handled = 0;
        foreach ($this->queue->due(100000) as $job) {
            $stats['checked']++;

            if (($job['type'] ?? null) !== 'deliver') {
                $stats['skipped']++;
                continue;
            }

            $claimed = $dryRun ? $job : $this->queue->claim($job);
            if ($claimed === null) {
                $stats['skipped']++;
                continue;
            }

            $handled++;
            try {
                $result = $this->deliver($claimed, $dryRun);

                if (($result['sent'] ?? false) === true) {
                    $this->queue->complete($claimed);
                    $stats['delivered']++;
                } else {
                    if (!$dryRun) {
                        $this->queue->fail($claimed, 'Delivery skipped before POST', 60);
                    }
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                if ($e instanceof IndeterminateDeliveryException) {
                    $claimed['delivery_uncertain'] = true;
                    $claimed['last_error'] = $e->getMessage();
                    $this->queue->complete($claimed);
                    $stats['skipped']++;
                    continue;
                }

                $attempts = ((int)($claimed['attempts'] ?? 0)) + 1;
                $maxAttempts = (int)($this->config['delivery_max_attempts'] ?? 8);

                if ($attempts >= $maxAttempts) {
                    $this->queue->dead($claimed, $e->getMessage());
                    $stats['dead']++;
                } else {
                    $this->queue->fail($claimed, $e->getMessage(), $this->retrySeconds($attempts));
                    $stats['failed']++;
                }
            }

            if ($handled >= $limit) {
                break;
            }
        }

        return $stats;
    }

    public function prepare(array $job): array
    {
        $payload = $job['payload'] ?? null;

        if (!is_array($payload)) {
            throw new RuntimeException('Delivery job payload is not an object');
        }

        $actor = $payload['actor'] ?? null;
        $inbox = $payload['inbox'] ?? null;
        $activity = $payload['activity'] ?? null;

        if (!is_string($actor) || !is_string($inbox) || !is_array($activity)) {
            throw new RuntimeException('Delivery job payload is incomplete');
        }

        $uid = $this->uidFromActor($actor);
        $secret = $this->keys->secretKey($uid);

        if ($secret === null) {
            throw new RuntimeException("Missing private key for {$uid}");
        }

        $body = Json::encode($activity);
        $headers = (new HttpSignature())->signedPostHeaders($inbox, $actor . '#main-key', $secret, $body);

        return [
            'uid' => $uid,
            'inbox' => $inbox,
            'body' => $body,
            'headers' => $headers,
        ];
    }

    private function deliver(array $job, bool $dryRun): array
    {
        $request = $this->prepare($job);

        if ($dryRun || !(bool)($this->config['delivery_enabled'] ?? false)) {
            return [
                'sent' => false,
                'prepared' => true,
                'inbox' => $request['inbox'],
            ];
        }

        $status = $this->post($request['inbox'], $request['headers'], $request['body']);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Remote inbox returned HTTP {$status}");
        }

        return [
            'sent' => true,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $url, array $headers, string $body): int
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;

        if ($scheme !== 'https' && !((bool)($this->config['allow_http_delivery'] ?? false) && $scheme === 'http')) {
            throw new RuntimeException('Refusing non-HTTPS delivery URL');
        }

        if (function_exists('curl_init')) {
            return $this->postWithCurl($url, $headers, $body);
        }

        return $this->postWithStream($url, $headers, $body);
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithCurl(string $url, array $headers, string $body): int
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize delivery request');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => min(10, (int)($this->config['delivery_timeout_seconds'] ?? 20)),
            CURLOPT_TIMEOUT => (int)($this->config['delivery_timeout_seconds'] ?? 20),
        ]);

        $result = curl_exec($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($curl);
        $error = curl_error($curl);
        $connectTime = (float)curl_getinfo($curl, CURLINFO_CONNECT_TIME);
        curl_close($curl);

        if ($result === false || $status === 0) {
            if (in_array($errno, [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_SSL_CONNECT_ERROR], true) || $connectTime <= 0.0) {
                throw new RuntimeException('Delivery network error before sending request: ' . ($error !== '' ? $error : 'no response'));
            }

            throw new IndeterminateDeliveryException('Delivery outcome is indeterminate after opening connection: ' . ($error !== '' ? $error : 'no HTTP status'));
        }

        return $status;
    }

    /**
     * @param array<string, string> $headers
     */
    private function postWithStream(string $url, array $headers, string $body): int
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => (int)($this->config['delivery_timeout_seconds'] ?? 20),
            ],
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false && !isset($http_response_header)) {
            throw new IndeterminateDeliveryException('Delivery request ended without a readable response');
        }

        $statusLine = $http_response_header[0] ?? '';

        if (!preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $statusLine, $match)) {
            throw new IndeterminateDeliveryException('Delivery response did not include an HTTP status');
        }

        return (int)$match[1];
    }

    private function uidFromActor(string $actor): string
    {
        $base = rtrim((string)$this->config['base_url'], '/') . (string)$this->config['local_actor_path'] . '/';

        if (!str_starts_with($actor, $base)) {
            throw new RuntimeException('Delivery actor is not local');
        }

        $uid = rawurldecode(substr($actor, strlen($base)));

        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid)) {
            throw new RuntimeException('Invalid local actor uid');
        }

        return $uid;
    }

    private function retrySeconds(int $attempts): int
    {
        return min(86400, 60 * (2 ** max(0, $attempts - 1)));
    }
}

final class IndeterminateDeliveryException extends RuntimeException
{
}
