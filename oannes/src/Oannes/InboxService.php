<?php

namespace Oannes;

use RuntimeException;

final class InboxService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly FileQueue $queue,
        private readonly ActorRepository $actors,
        private readonly array $config,
    ) {
    }

    public function receive(string $uid, string $method, string $requestTarget, array $headers, string $body): array
    {
        if (!(bool)($this->config['inbox_enabled'] ?? false)) {
            throw new RuntimeException('Inbox is disabled');
        }

        if ($method !== 'POST') {
            throw new RuntimeException('Inbox only accepts POST');
        }

        $maxBytes = (int)($this->config['inbox_max_bytes'] ?? 262144);
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException('Inbox body is too large');
        }

        $activity = Json::decode($body, 'inbox body');
        $actorId = $this->actorIdFromActivity($activity);

        if ((new SocialRelationService($this->store))->isBlocked($uid, $actorId)) {
            throw new RuntimeException('Blocked actor');
        }

        if ((new InstanceSettings($this->store, $this->config))->isActorBlocked($actorId)) {
            throw new RuntimeException('Blocked by instance');
        }

        $actor = $this->actors->findById($actorId);

        if ($actor === null) {
            throw new RuntimeException('Unknown signed actor');
        }

        $keyId = (new HttpSignature())->keyId($headers);
        $actorKeyId = $this->publicKeyIdFromActor($actor);

        if ($keyId === null || $actorKeyId === null || $keyId !== $actorKeyId) {
            throw new RuntimeException('HTTP Signature key does not match activity actor');
        }

        $publicKey = $this->publicKeyFromActor($actor);

        if ($publicKey === null) {
            throw new RuntimeException('Signed actor has no public key');
        }

        $signature = new HttpSignature();
        $signedHeaders = $signature->signedHeaderNames($headers);

        foreach (['(request-target)', 'host', 'date', 'digest'] as $requiredHeader) {
            if (!in_array($requiredHeader, $signedHeaders, true)) {
                throw new RuntimeException("HTTP Signature does not cover {$requiredHeader}");
            }
        }

        $this->assertFreshDate($headers);

        if (!$signature->verifyDigest($headers, $body)) {
            throw new RuntimeException('Invalid Digest header');
        }

        if (!$signature->verifyRequest($headers, strtolower($method), $requestTarget, $publicKey)) {
            throw new RuntimeException('Invalid HTTP Signature');
        }

        $fingerprint = $this->activityFingerprint($activity, $body);
        $marker = $this->store->dataDir() . '/inbox/fingerprints/' . $fingerprint . '.json';

        if (is_file($marker)) {
            throw new RuntimeException('Duplicate inbox activity');
        }

        $id = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
        $path = $this->store->dataDir() . '/inbox/accepted/' . $uid . '/' . $id . '.json';
        $record = [
            'id' => $id,
            'received_at' => gmdate('c'),
            'local_uid' => $uid,
            'actor' => $actorId,
            'activity' => $activity,
        ];

        $this->store->writeJson($path, $record);
        $this->store->writeJson($marker, [
            'fingerprint' => $fingerprint,
            'activity_id' => ActivityPub::objectId($activity),
            'record' => $path,
            'received_at' => $record['received_at'],
        ]);
        $this->queue->enqueue('inbox', [
            'record' => $path,
            'local_uid' => $uid,
            'actor' => $actorId,
        ]);

        return [
            'ok' => true,
            'id' => $id,
            'queued' => true,
        ];
    }

    private function actorIdFromActivity(array $activity): string
    {
        $actor = $activity['actor'] ?? null;

        if (is_string($actor) && $actor !== '') {
            return $actor;
        }

        if (is_array($actor)) {
            $id = ActivityPub::objectId($actor);
            if ($id !== null) {
                return $id;
            }
        }

        throw new RuntimeException('Inbox activity has no actor');
    }

    private function publicKeyFromActor(array $actor): ?string
    {
        $key = $actor['publicKey'] ?? null;

        if (is_array($key) && is_string($key['publicKeyPem'] ?? null) && $key['publicKeyPem'] !== '') {
            return $key['publicKeyPem'];
        }

        return null;
    }

    private function publicKeyIdFromActor(array $actor): ?string
    {
        $key = $actor['publicKey'] ?? null;

        if (is_array($key) && is_string($key['id'] ?? null) && $key['id'] !== '') {
            return $key['id'];
        }

        return null;
    }

    private function assertFreshDate(array $headers): void
    {
        $date = $headers['date'] ?? $headers['Date'] ?? null;

        if (!is_string($date) || trim($date) === '') {
            throw new RuntimeException('Missing Date header');
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            throw new RuntimeException('Invalid Date header');
        }

        $maxSkew = (int)($this->config['inbox_max_clock_skew_seconds'] ?? 43200);

        if (abs(time() - $timestamp) > $maxSkew) {
            throw new RuntimeException('Date header is outside accepted clock skew');
        }
    }

    private function activityFingerprint(array $activity, string $body): string
    {
        $id = ActivityPub::objectId($activity);
        if (($activity['type'] ?? null) === 'Update' && $id !== null) {
            return Id::digest('Update' . "\n" . $id . "\n" . hash('sha256', $body));
        }

        return Id::digest($id ?? $body);
    }
}
