<?php

namespace Oannes;

final class RemoteActorResolver
{
    public function __construct(
        private readonly FileStore $store,
        private readonly LocalUsers $users,
        private readonly array $config,
    ) {
    }

    public function resolve(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            throw new \RuntimeException('Indica un usuario a seguir.');
        }

        foreach ($this->users->all() as $uid => $user) {
            $uid = (string)$uid;
            $acct = '@' . $uid . '@' . (string)$this->config['host'];
            $localHandle = '@' . $uid;
            $ids = array_merge([$this->users->actorId($uid), $this->users->webUrl($uid)], $this->users->legacyActorIds($uid));

            if ($input === $localHandle || $input === $acct || in_array($input, $ids, true)) {
                return $this->users->activityPubActor($uid, is_array($user) ? $user : []);
            }
        }

        $actorId = str_starts_with($input, 'http://') || str_starts_with($input, 'https://')
            ? $input
            : $this->actorIdFromAcct($input);

        if ((new InstanceSettings($this->store, $this->config))->isActorBlocked($actorId)) {
            throw new \RuntimeException('Ese actor o su servidor está bloqueado en esta instancia.');
        }

        $cached = (new ActorRepository($this->store))->findById($actorId);
        if ($cached !== null) {
            return $cached;
        }

        $actor = $this->fetchJson($actorId, 'application/activity+json, application/ld+json; profile="https://www.w3.org/ns/activitystreams", application/json');

        if (!ActivityPub::isActor($actor)) {
            throw new \RuntimeException('La URL indicada no devuelve un actor ActivityPub.');
        }

        $id = ActivityPub::objectId($actor);
        if ($id !== null && (new InstanceSettings($this->store, $this->config))->isActorBlocked($id)) {
            throw new \RuntimeException('Ese actor o su servidor está bloqueado en esta instancia.');
        }

        $this->store->writeActor($actor);
        return $actor;
    }

    private function actorIdFromAcct(string $input): string
    {
        if (!preg_match('/^@?([^@\s]+)@([^@\s]+)$/', $input, $match)) {
            throw new \RuntimeException('Usa una URL de actor o una dirección tipo @usuario@servidor.org.');
        }

        $resource = 'acct:' . $match[1] . '@' . $match[2];
        $url = 'https://' . $match[2] . '/.well-known/webfinger?resource=' . rawurlencode($resource);
        $json = $this->fetchJson($url, 'application/jrd+json, application/json');
        $links = is_array($json['links'] ?? null) ? $json['links'] : [];

        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }

            if (($link['rel'] ?? null) === 'self' && is_string($link['href'] ?? null) && $link['href'] !== '') {
                return $link['href'];
            }
        }

        throw new \RuntimeException('WebFinger no devolvió un actor ActivityPub.');
    }

    private function fetchJson(string $url, string $accept): array
    {
        if (!str_starts_with($url, 'https://')) {
            throw new \RuntimeException('Sólo se aceptan URLs HTTPS.');
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: {$accept}\r\nUser-Agent: Uanna/0.1\r\n",
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = $this->responseStatus($http_response_header ?? []);

        if ($body === false || in_array($status, [401, 403], true)) {
            $headers = $this->signedGetHeaders($url, $accept);
            if ($headers !== []) {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => $this->formatHeaders($headers) . "User-Agent: Uanna/0.1\r\n",
                        'ignore_errors' => true,
                        'timeout' => 15,
                    ],
                ]);
                $body = @file_get_contents($url, false, $context);
            }
        }

        if ($body === false) {
            throw new \RuntimeException('No se pudo consultar el actor remoto.');
        }

        return Json::decode($body, $url);
    }

    /**
     * @return array<string, string>
     */
    private function signedGetHeaders(string $url, string $accept): array
    {
        $keys = new KeyStore($this->store);

        foreach ($this->users->all() as $uid => $user) {
            if (!is_string($uid) || !is_array($user)) {
                continue;
            }

            $secret = $keys->secretKey($uid);
            if ($secret === null) {
                continue;
            }

            return (new HttpSignature())->signedGetHeaders($url, $this->users->actorId($uid) . '#main-key', $secret, $accept);
        }

        return [];
    }

    /**
     * @param list<string> $headers
     */
    private function responseStatus(array $headers): int
    {
        $line = $headers[0] ?? '';
        if (is_string($line) && preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $match)) {
            return (int)$match[1];
        }

        return 0;
    }

    /**
     * @param array<string, string> $headers
     */
    private function formatHeaders(array $headers): string
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }

        return $lines;
    }
}
