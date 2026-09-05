<?php

namespace Oannes;

final class Router
{
    private bool $timelineInboxRefreshed = false;
    private ?InteractionService $apiInteractionService = null;
    private array $notificationInteractionReasonCache = [];
    private array $threadParticipationCache = [];

    public function __construct(
        private readonly array $config,
        private readonly FileStore $store,
        private readonly ObjectRepository $repo,
        private readonly Renderer $renderer,
        private readonly LocalUsers $users,
        private readonly ?Auth $auth = null,
    ) {
    }

    public function dispatch(): void
    {
        $route = $_GET['route'] ?? '';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        if (!is_string($route)) {
            $route = '';
        }

        if ($route === '') {
            $route = $this->routeFromRequestPath();
        }

        if ($route === 'favicon.ico') {
            $this->favicon();
            return;
        }

        if ($route === 'attachment-download') {
            $this->attachmentDownload($method);
            return;
        }

        if ($route === 'api' || str_starts_with($route, 'api/')) {
            $this->api($route, $method);
            return;
        }

        if ($this->throttleRepeatedNammuFetch($method)) {
            return;
        }

        $this->runOpportunisticMaintenance($route, $method);

        if ($this->users->all() === [] && $route !== 'setup') {
            $this->setup($method);
            return;
        }

        if ($route === 'setup') {
            $this->setup($method);
            return;
        }

        if ($route === '.well-known/webfinger') {
            $this->webfinger();
            return;
        }

        if ($route === '.well-known/nodeinfo') {
            $this->nodeInfoLinks();
            return;
        }

        if ($route === 'nodeinfo/2.1') {
            $this->nodeInfo();
            return;
        }

        if (preg_match('#^u/([^/]+)$#', $route, $match)) {
            $this->actor($match[1], true);
            return;
        }

        if (preg_match('#^legacy-user/([^/]+)$#', $route, $match)) {
            $this->actor($match[1]);
            return;
        }

        if (preg_match('#^legacy-actor/([^/]+)$#', $route, $match)) {
            $this->actor($match[1], true);
            return;
        }

        if (preg_match('#^legacy-user/([^/]+)/outbox$#', $route, $match)) {
            $this->outbox($match[1]);
            return;
        }

        if (preg_match('#^legacy-user/([^/]+)/(followers|following)$#', $route, $match)) {
            $this->socialCollection($match[1], $match[2]);
            return;
        }

        if (preg_match('#^legacy-user/([^/]+)/inbox$#', $route, $match)) {
            if ($method !== 'POST') {
                Http::methodNotAllowed();
                return;
            }

            $this->inbox($match[1], $method);
            return;
        }

        if (preg_match('#^u/([^/]+)/outbox$#', $route, $match)) {
            $this->outbox($match[1]);
            return;
        }

        if (preg_match('#^u/([^/]+)/p/([^/]+)/replies$#', $route, $match)) {
            $this->repliesCollection($match[1], $match[2]);
            return;
        }

        if (preg_match('#^u/([^/]+)/(followers|following)$#', $route, $match)) {
            $this->socialCollection($match[1], $match[2]);
            return;
        }

        if (preg_match('#^u/([^/]+)/inbox$#', $route, $match)) {
            if ($method !== 'POST') {
                Http::methodNotAllowed();
                return;
            }

            $this->inbox($match[1], $method);
            return;
        }

        if ($route === 'admin/login') {
            $this->adminLogin($method);
            return;
        }

        if ($route === 'timeline-more') {
            $this->timelineMore($method);
            return;
        }

        if ($route === 'admin/logout') {
            $this->adminLogout($method);
            return;
        }

        if ($route === 'admin/post') {
            $this->adminPost($method);
            return;
        }

        if ($route === 'admin/post-edit') {
            $this->adminPostEdit($method);
            return;
        }

        if ($route === 'admin/post-delete') {
            $this->adminPostDelete($method);
            return;
        }

        if ($route === 'admin/profile') {
            $this->adminProfile($method);
            return;
        }

        if ($route === 'admin/export-user') {
            $this->adminExportUser($method);
            return;
        }

        if ($route === 'admin/delete-content') {
            $this->adminDeleteContent($method);
            return;
        }

        if ($route === 'admin/delete-account') {
            $this->adminDeleteAccount($method);
            return;
        }

        if ($route === 'admin/react') {
            $this->adminReact($method);
            return;
        }

        if ($route === 'admin/private-message') {
            $this->adminPrivateMessage($method);
            return;
        }

        if ($route === 'admin/connected') {
            $this->adminConnected($method);
            return;
        }

        if ($route === 'instance-admin/social-graph') {
            $this->instanceAdminSocialGraph($method);
            return;
        }

        if ($route === 'admin/social') {
            $this->adminSocial($method);
            return;
        }

        if ($route === 'instance-admin') {
            $this->instanceAdmin();
            return;
        }

        if ($route === 'instance-admin/settings') {
            $this->instanceAdminSettings($method);
            return;
        }

        if ($route === 'instance-admin/server-blocks') {
            $this->instanceAdminServerBlocks($method);
            return;
        }

        if ($route === 'instance-admin/actor-block') {
            $this->instanceAdminActorBlock($method);
            return;
        }

        if ($route === 'instance-admin/users') {
            $this->instanceAdminUsers($method);
            return;
        }

        if ($route === 'instance-admin/import-user') {
            $this->instanceAdminImportUser($method);
            return;
        }

        if ($route === 'instance-admin/socialize-user') {
            $this->instanceAdminSocializeUser($method);
            return;
        }

        if ($route === 'instance-admin/default-following') {
            $this->instanceAdminDefaultFollowing($method);
            return;
        }

        if ($route === 'instance-admin/compile-app') {
            $this->instanceAdminCompileApp($method);
            return;
        }

        if ($route === 'admin/moderation/follow') {
            $this->adminModerationFollow($method);
            return;
        }

        if ($route === 'admin/moderation/create') {
            $this->adminModerationCreate($method);
            return;
        }

        if ($route === 'admin') {
            $this->admin();
            return;
        }

        if ($route === 'not-found') {
            header('Cache-Control: public, max-age=60');
            Http::notFound();
            return;
        }

        if ($route === 'gone-object') {
            $id = is_string($_GET['id'] ?? null) ? $_GET['id'] : '';
            header('Cache-Control: public, max-age=86400');
            Http::gone([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $id,
                'type' => 'Tombstone',
            ]);
            return;
        }

        $this->html();
    }

    private function routeFromRequestPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = is_string($uri) ? (parse_url($uri, PHP_URL_PATH) ?: '/') : '/';
        $path = trim($path, '/');

        if ($path === '' || $path === 'index.php') {
            return '';
        }

        if ($path === 'favicon.ico') {
            return 'favicon.ico';
        }

        if ($path === '.well-known/webfinger' || $path === '.well-known/nodeinfo' || $path === 'nodeinfo/2.1') {
            return $path;
        }

        if ($path === 'api' || str_starts_with($path, 'api/')) {
            return $path;
        }

        if (preg_match('#^u/[a-zA-Z0-9_-]+(?:/(?:outbox|inbox|followers|following))?$#', $path)) {
            return $path;
        }

        if (preg_match('#^u/[a-zA-Z0-9_-]+/p/[^/]+/replies$#', $path)) {
            return $path;
        }

        if (preg_match('#^([a-zA-Z0-9_-]{1,64})/(outbox|inbox|followers|following)$#', $path, $match) && $this->users->find($match[1]) !== null) {
            return 'legacy-user/' . $match[1] . '/' . $match[2];
        }

        if (preg_match('/^@([a-zA-Z0-9_-]{1,64})$/', $path, $match) && $this->users->find($match[1]) !== null) {
            return 'legacy-user/' . $match[1];
        }

        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $path) && $this->users->find($path) !== null) {
            return 'legacy-actor/' . $path;
        }

        $id = rtrim((string)$this->config['base_url'], '/') . '/' . $path;

        if (preg_match('#^u/[a-zA-Z0-9_-]+/p/[^/]+$#', $path) === 1) {
            if (is_file(Id::objectPath($this->store->dataDir(), $id))) {
                $_GET['id'] = $id;
                return '';
            }

            $_GET['id'] = $id;
            return 'gone-object';
        }

        if ($this->repo->findByIdOrAlias($id) !== null) {
            $_GET['id'] = $id;
            return '';
        }

        return 'not-found';
    }

    private function favicon(): void
    {
        $settings = new InstanceSettings($this->store, $this->config);
        $path = $settings->faviconPath();

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            header('Location: ' . $path, true, 302);
            return;
        }

        $publicDir = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/');
        $file = $publicDir . '/' . ltrim($path, '/');

        if (!is_file($file)) {
            $file = dirname(__DIR__, 3) . '/uanna.png';
        }

        if (!is_file($file)) {
            Http::notFound();
            return;
        }

        $type = mime_content_type($file) ?: 'image/png';
        header('Content-Type: ' . $type);
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
    }

    private function attachmentDownload(string $method): void
    {
        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        $url = $_GET['url'] ?? '';
        if (!is_string($url) || trim($url) === '') {
            Http::notFound();
            return;
        }

        $url = trim($url);
        foreach ($this->localImageCandidates($url) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $type = mime_content_type($file) ?: 'application/octet-stream';
            if (!str_starts_with(strtolower($type), 'image/')) {
                continue;
            }

            $this->sendAttachmentHeaders($type, $this->downloadFilename($url, $type), filesize($file) ?: null);
            readfile($file);
            return;
        }

        if (!str_starts_with($url, 'https://')) {
            Http::notFound();
            return;
        }

        $maxBytes = max(1, (int)($this->config['max_attachment_bytes'] ?? 26214400));
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: image/*\r\nUser-Agent: Uanna attachment download\r\n",
                'ignore_errors' => true,
                'timeout' => 20,
            ],
        ]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if (!is_string($body) || $body === '' || strlen($body) > $maxBytes) {
            Http::notFound();
            return;
        }

        $type = $this->responseContentType($http_response_header ?? []);
        if ($type === null || !str_starts_with(strtolower($type), 'image/')) {
            $info = @getimagesizefromstring($body);
            $type = is_array($info) && is_string($info['mime'] ?? null) ? $info['mime'] : null;
        }

        if ($type === null || !str_starts_with(strtolower($type), 'image/')) {
            Http::notFound();
            return;
        }

        $this->sendAttachmentHeaders($type, $this->downloadFilename($url, $type), strlen($body));
        echo $body;
    }

    private function responseContentType(array $headers): ?string
    {
        foreach ($headers as $header) {
            if (!is_string($header) || stripos($header, 'Content-Type:') !== 0) {
                continue;
            }

            $type = trim(substr($header, strlen('Content-Type:')));
            $type = trim(explode(';', $type, 2)[0]);
            if ($type !== '') {
                return $type;
            }
        }

        return null;
    }

    private function sendAttachmentHeaders(string $type, string $filename, ?int $length): void
    {
        header('Content-Type: ' . $type);
        header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\\\"") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
        header('Cache-Control: private, max-age=300');
        if ($length !== null) {
            header('Content-Length: ' . (string)$length);
        }
    }

    private function downloadFilename(string $url, string $type): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $name = is_string($path) ? basename(rawurldecode($path)) : '';
        $name = preg_replace('/[^\pL\pN._-]+/u', '-', $name) ?? '';
        $name = trim($name, '.-');

        if ($name === '') {
            $extension = match (strtolower($type)) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $name = 'imagen.' . $extension;
        }

        return $name;
    }

    private function html(): void
    {
        $id = $_GET['id'] ?? null;
        $uid = $_GET['user'] ?? null;
        $actor = $_GET['actor'] ?? null;
        $tag = $_GET['tag'] ?? null;

        if (is_string($id) && $id !== '') {
            $format = $_GET['format'] ?? '';
            if ($format === 'xml') {
                $object = $this->repo->findByIdOrAlias($id);
                if ($object === null || !ActivityPub::isPublicObject($object) || $this->objectBlocked($object)) {
                    Http::notFound();
                    return;
                }

                header('Content-Type: application/xml; charset=utf-8');
                echo (new XmlExporter())->objectToXml($object);
                return;
            }

            if (Http::wantsActivityJson()) {
                if ($this->serveCachedActivityObject($id)) {
                    return;
                }

                $object = $this->repo->findByIdOrAlias($id);
                if ($object === null || !ActivityPub::isPublicObject($object) || $this->objectBlocked($object)) {
                    Http::notFound();
                    return;
                }

                $object = $this->withPublicRepliesCollection($object);
                $this->cacheActivityObject($id, $object);
                Http::activityJson($object);
                return;
            }

            echo $this->renderer->objectPage($id, $this->currentActions());
            return;
        }

        if (is_string($tag) && trim($tag) !== '') {
            $this->tagPage($tag);
            return;
        }

        if (is_string($uid) && $uid !== '') {
            echo $this->renderer->userPage($uid, $this->currentActions());
            return;
        }

        if (is_string($actor) && $actor !== '') {
            echo $this->renderer->actorPage($actor, $this->currentActions());
            return;
        }

        $auth = $this->auth ?? new Auth($this->store);
        $currentUid = $auth->currentUser();

        if ($currentUid !== null) {
            $pageSize = $this->timelinePageSize();
            $objects = $this->privateTimeline($currentUid, $pageSize + 1);
            $hasMore = count($objects) > $pageSize;
            $objects = array_slice($objects, 0, $pageSize);
            $nextUrl = $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'private', 'offset' => $pageSize]) : '';
            $currentUser = $this->users->find($currentUid);
            echo $this->renderer->privateTimelinePage(
                $currentUid,
                $objects,
                $auth->csrfToken(),
                $nextUrl,
                is_array($currentUser) && (bool)($currentUser['admin'] ?? false)
            );
            return;
        }

        echo $this->renderer->home();
    }

    private function webfinger(): void
    {
        $resource = $_GET['resource'] ?? '';

        if (!is_string($resource) || !preg_match('/^acct:([^@]+)@(.+)$/', $resource, $match)) {
            Http::notFound();
            return;
        }

        $uid = $match[1];
        $host = $match[2];

        if ($host !== $this->config['host'] || $this->users->find($uid) === null) {
            Http::notFound();
            return;
        }

        Http::json([
            'subject' => 'acct:' . $uid . '@' . $this->config['host'],
            'aliases' => array_values(array_unique(array_merge(
                [
                    $this->users->actorId($uid),
                    $this->users->webUrl($uid),
                ],
                $this->users->legacyActorIds($uid),
            ))),
            'links' => [
                [
                    'rel' => 'self',
                    'type' => 'application/activity+json',
                    'href' => $this->users->actorId($uid),
                ],
                [
                    'rel' => 'http://webfinger.net/rel/profile-page',
                    'type' => 'text/html',
                    'href' => $this->users->webUrl($uid),
                ],
            ],
        ], 'application/jrd+json');
    }

    private function serveCachedActivityObject(string $id): bool
    {
        $path = $this->activityObjectCachePath($id);

        if (!is_file($path) || time() - filemtime($path) > 300) {
            return false;
        }

        $json = file_get_contents($path);
        if (!is_string($json) || $json === '') {
            return false;
        }

        Http::cachedActivityJson($json);
        return true;
    }

    private function cacheActivityObject(string $id, array $object): void
    {
        $path = $this->activityObjectCachePath($id);
        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        @chmod($dir, 02775);
        @file_put_contents($path, Json::encode($object), LOCK_EX);
        @chmod($path, 0664);
    }

    private function activityObjectCachePath(string $id): string
    {
        return $this->store->dataDir() . '/cache/activity-objects/' . Id::digest($id) . '.json';
    }

    private function withPublicRepliesCollection(array $object): array
    {
        $id = ActivityPub::objectId($object);
        if ($id === null || !$this->isLocalActorObject($object)) {
            return $object;
        }

        $items = $this->publicReplyItems($id);
        $collectionId = $id . '/replies';
        $object['replies'] = [
            'id' => $collectionId,
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'first' => [
                'id' => $collectionId . '?page=true',
                'type' => 'OrderedCollectionPage',
                'partOf' => $collectionId,
                'items' => $items,
                'orderedItems' => $items,
            ],
        ];

        return $object;
    }

    private function publicReplyItems(string $objectId): array
    {
        $items = [];

        foreach ($this->repo->childrenOf($objectId) as $child) {
            if (!ActivityPub::isPublicObject($child) || $this->objectBlocked($child)) {
                continue;
            }

            $items[] = $this->withPublicRepliesSummary($child);
        }

        usort($items, static fn (array $a, array $b): int => strcmp(
            ActivityPub::published($a),
            ActivityPub::published($b)
        ));

        return $items;
    }

    private function withPublicRepliesSummary(array $object): array
    {
        $id = ActivityPub::objectId($object);
        if ($id === null || !$this->isLocalActorObject($object)) {
            return $object;
        }

        $replyCount = 0;
        foreach ($this->repo->childrenOf($id) as $child) {
            if (ActivityPub::isPublicObject($child) && !$this->objectBlocked($child)) {
                $replyCount++;
            }
        }

        $collectionId = $id . '/replies';
        $object['replies'] = [
            'id' => $collectionId,
            'type' => 'OrderedCollection',
            'totalItems' => $replyCount,
            'first' => [
                'id' => $collectionId . '?page=true',
                'type' => 'OrderedCollectionPage',
                'partOf' => $collectionId,
            ],
        ];

        return $object;
    }

    private function isLocalActorObject(array $object): bool
    {
        $actor = ActivityPub::attributedTo($object);

        return $actor !== null && $this->isLocalActorId($actor);
    }

    private function throttleRepeatedNammuFetch(string $method): bool
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        if (!is_string($userAgent) || !str_contains($userAgent, 'Nammu Fediverso')) {
            return false;
        }

        $path = parse_url(Http::requestTarget(), PHP_URL_PATH);
        if (!is_string($path) || preg_match('#^/u/[a-zA-Z0-9_-]+/p/[^/]+(?:/replies)?$#', $path) !== 1) {
            return false;
        }

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $addr = is_string($remoteAddr) ? $remoteAddr : '';

        if ($this->incrementThrottleCount('nammu-global ' . $addr) > 90) {
            Http::tooManyRequests(300);
            return true;
        }

        if ($this->incrementThrottleCount('nammu-path ' . $addr . ' ' . $path) <= 30) {
            return false;
        }

        Http::tooManyRequests(300);
        return true;
    }

    private function incrementThrottleCount(string $key): int
    {
        $counterPath = $this->store->dataDir() . '/cache/request-throttle/' . Id::digest($key) . '.json';
        $now = time();
        $state = ['window' => $now, 'count' => 0];

        if (is_file($counterPath)) {
            try {
                $loaded = $this->store->readJson($counterPath);
                if (is_int($loaded['window'] ?? null) && $now - $loaded['window'] < 60) {
                    $state = [
                        'window' => $loaded['window'],
                        'count' => is_int($loaded['count'] ?? null) ? $loaded['count'] : 0,
                    ];
                }
            } catch (\Throwable) {
                $state = ['window' => $now, 'count' => 0];
            }
        }

        $state['count']++;

        try {
            $this->store->writeJson($counterPath, $state);
        } catch (\Throwable) {
            return 0;
        }

        return $state['count'];
    }

    private function nodeInfoLinks(): void
    {
        $base = rtrim((string)$this->config['base_url'], '/');

        Http::json([
            'links' => [
                [
                    'rel' => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                    'href' => $base . (string)$this->config['public_path'] . '?route=nodeinfo/2.1',
                ],
            ],
        ]);
    }

    private function nodeInfo(): void
    {
        $users = $this->users->all();
        $manifest = $this->store->readJson($this->store->dataDir() . '/indexes/manifest.json');

        Http::json([
            'version' => '2.1',
            'software' => [
                'name' => strtolower((string)$this->config['software_name']),
                'version' => (string)$this->config['software_version'],
            ],
            'protocols' => ['activitypub'],
            'services' => [
                'inbound' => [],
                'outbound' => [],
            ],
            'openRegistrations' => (bool)$this->config['allow_public_signup'],
            'usage' => [
                'users' => [
                    'total' => count($users),
                ],
                'localPosts' => $this->countLocalPosts(),
            ],
            'metadata' => [
                'communityMode' => $this->config['community_mode'],
                'storage' => 'files-json-xml',
                'counts' => $manifest['counts'] ?? null,
            ],
        ]);
    }

    private function actor(string $uid, bool $forceActivityJson = false): void
    {
        $user = $this->users->find($uid);

        if ($user === null) {
            Http::notFound();
            return;
        }

        if ($forceActivityJson || Http::wantsActivityJson()) {
            Http::activityJson($this->users->activityPubActor($uid, $user));
            return;
        }

        echo $this->renderer->userPage($uid, $this->currentActions());
    }

    private function currentActions(): ?array
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            return null;
        }

        $user = $this->users->find($uid);

        return [
            'uid' => $uid,
            'is_admin' => is_array($user) && (bool)($user['admin'] ?? false),
            'csrf' => $auth->csrfToken(),
        ];
    }

    private function outbox(string $uid): void
    {
        if ($this->users->find($uid) === null) {
            Http::notFound();
            return;
        }

        $actorId = $this->users->actorId($uid);
        $objects = $this->repo->byAnyActor(array_merge([$actorId], $this->users->legacyActorIds($uid)), 50);
        $objects = $this->publicObjects($objects);
        $items = array_values(array_map(fn (array $object): array => $this->createActivityForOutbox($object), $objects));

        Http::activityJson([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count($objects),
            'orderedItems' => $items,
        ]);
    }

    private function createActivityForOutbox(array $object): array
    {
        $id = ActivityPub::objectId($object) ?? '';

        return [
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $id . '#create',
            'type' => 'Create',
            'actor' => ActivityPub::attributedTo($object) ?? '',
            'published' => ActivityPub::published($object),
            'to' => is_array($object['to'] ?? null) ? $object['to'] : [],
            'cc' => is_array($object['cc'] ?? null) ? $object['cc'] : [],
            'object' => $object,
        ];
    }

    private function publicObjects(array $objects): array
    {
        return array_values(array_filter($objects, fn (array $object): bool => ActivityPub::isPublicObject($object) && !$this->objectBlocked($object)));
    }

    private function inbox(string $uid, string $method): void
    {
        if ($this->users->find($uid) === null) {
            Http::notFound();
            return;
        }

        try {
            $result = (new InboxService(
                $this->store,
                new FileQueue($this->store),
                new ActorRepository($this->store),
                $this->config,
            ))->receive($uid, $method, Http::requestTarget(), Http::requestHeaders(), file_get_contents('php://input') ?: '');
        } catch (\RuntimeException $e) {
            Http::json(['error' => $e->getMessage()], 'application/json', 401);
            return;
        }

        Http::json($result, 'application/json', 202);
    }

    private function socialCollection(string $uid, string $kind): void
    {
        if ($this->users->find($uid) === null) {
            Http::notFound();
            return;
        }

        $graph = new SocialGraph($this->store);
        $actors = $kind === 'followers' ? $graph->followers($uid) : $graph->following($uid);
        $items = [];

        if ((bool)($this->config['expose_social_graph'] ?? false)) {
            foreach ($actors as $actor) {
                $id = ActivityPub::objectId($actor);
                if ($id !== null) {
                    $items[] = $id;
                }
            }
        }

        $actorId = $this->users->actorId($uid);

        Http::activityJson([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/' . $kind,
            'type' => 'OrderedCollection',
            'totalItems' => count($actors),
            'orderedItems' => $items,
        ]);
    }

    private function repliesCollection(string $uid, string $postId): void
    {
        if ($this->users->find($uid) === null) {
            Http::notFound();
            return;
        }

        $objectId = rtrim((string)$this->config['base_url'], '/') . '/u/' . rawurlencode($uid) . '/p/' . $postId;
        $object = $this->repo->findByIdOrAlias($objectId);
        $actor = is_array($object) ? ActivityPub::attributedTo($object) : null;

        if ($object === null || $actor === null || !in_array($actor, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true)) {
            Http::notFound();
            return;
        }

        if (!ActivityPub::isPublicObject($object) || $this->objectBlocked($object)) {
            Http::notFound();
            return;
        }

        $items = $this->publicReplyItems($objectId);
        $collectionId = $objectId . '/replies';
        $isPage = (string)($_GET['page'] ?? '') === 'true';

        if ($isPage) {
            Http::activityJson([
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $collectionId . '?page=true',
                'type' => 'OrderedCollectionPage',
                'partOf' => $collectionId,
                'items' => $items,
                'orderedItems' => $items,
            ]);
            return;
        }

        Http::activityJson([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $collectionId,
            'type' => 'OrderedCollection',
            'totalItems' => count($items),
            'first' => [
                'id' => $collectionId . '?page=true',
                'type' => 'OrderedCollectionPage',
                'partOf' => $collectionId,
                'items' => $items,
                'orderedItems' => $items,
            ],
        ]);
    }

    private function countLocalPosts(): int
    {
        $total = 0;

        foreach ($this->users->all() as $uid => $_user) {
            if (is_string($uid)) {
                $total += $this->repo->countByAnyActor(array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)));
            }
        }

        return $total;
    }

    private function adminLogin(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $admin = new AdminRenderer($this->renderer, $auth);

        if ($method !== 'POST') {
            echo $admin->login();
            return;
        }

        $uid = $_POST['uid'] ?? '';
        $password = $_POST['password'] ?? '';
        $csrf = $_POST['csrf'] ?? null;

        if (!is_string($uid) || !is_string($password) || !$auth->checkCsrf(is_string($csrf) ? $csrf : null)) {
            echo $admin->login('Solicitud no válida.');
            return;
        }

        if (!$auth->login($uid, $password)) {
            echo $admin->login('Usuario o clave incorrectos.');
            return;
        }

        header('Location: ' . $this->homeLocation());
    }

    private function setup(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $admin = new AdminRenderer($this->renderer, $auth);

        if ($this->users->all() !== []) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            echo $admin->setup();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $uid = $_POST['uid'] ?? '';
        $name = $_POST['name'] ?? '';
        $password = $_POST['password'] ?? '';

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($uid) || !is_string($name) || !is_string($password) || $password === '') {
            echo $admin->setup('Solicitud no válida.');
            return;
        }

        try {
            $this->users->create($uid, $name, true);
            $auth->setPassword($uid, $password);
            $auth->login($uid, $password);
        } catch (\Throwable $e) {
            echo $admin->setup($e->getMessage());
            return;
        }

        header('Location: ' . $this->homeLocation());
    }

    private function homeLocation(): string
    {
        $path = (string)($this->config['public_path'] ?? '');
        return $path === '' ? '/' : $path;
    }

    private function adminLogout(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);

        if ($method === 'POST' && $auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $auth->logout();
        }

        header('Location: ?route=admin/login');
    }

    private function admin(): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        echo $this->adminDashboard($uid, $auth);
    }

    private function requireInstanceAdmin(): array
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            exit;
        }

        $user = $this->users->find($uid);
        if (!is_array($user) || !((bool)($user['admin'] ?? false))) {
            Http::notFound();
            exit;
        }

        return [$uid, $auth];
    }

    private function instanceAdmin(?string $message = null, ?string $error = null, string $openBox = ''): void
    {
        [$uid, $auth] = $this->requireInstanceAdmin();
        $settings = new InstanceSettings($this->store, $this->config);
        $settingsData = $settings->all();
        $settingsData['default_language'] ??= $settings->defaultLanguage();

        echo (new AdminRenderer($this->renderer, $auth))->instanceAdmin(
            $uid,
            $this->users->all(),
            $settingsData,
            $settings->blockedServers(),
            $settings->blockedActors(),
            $settings->blockNotices(),
            $settings->defaultFollowingActors(),
            $message,
            $error,
            $openBox,
            (new AndroidAppBuilder($this->store, $this->config))->status(),
            (new LanguageCatalog($this->store, $this->config))->available()
        );
    }

    private function instanceAdminSettings(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        try {
            $assets = new InstanceAssetService([...$this->config, 'public_dir' => dirname(__DIR__, 2) . '/public']);
            $fields = [];
            $savedUpdateMode = false;
            foreach (['instance_name', 'presentation_html'] as $field) {
                if (is_string($_POST[$field] ?? null)) {
                    $fields[$field] = $_POST[$field];
                }
            }

            if (is_string($_POST['default_language'] ?? null)) {
                $fields['default_language'] = (new LanguageCatalog($this->store, $this->config))->validate($_POST['default_language']);
            }

            if (is_string($_POST['update_mode'] ?? null)) {
                $mode = $_POST['update_mode'];
                if (!in_array($mode, ['activity', 'cron'], true)) {
                    throw new \RuntimeException('Modo de actualización no válido.');
                }

                $fields['update_mode'] = $mode;
                $savedUpdateMode = true;
            }

            foreach (['favicon', 'default_avatar', 'default_header'] as $field) {
                $path = $assets->saveImageFromPost($field);
                if ($path !== null) {
                    $fields[$field] = $path;
                }
            }
            (new InstanceSettings($this->store, $this->config))->update($fields);
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Instancia guardada.', null, $savedUpdateMode ? 'updates' : '');
    }

    private function instanceAdminServerBlocks(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        $settings = new InstanceSettings($this->store, $this->config);
        $server = is_string($_POST['server'] ?? null) ? $_POST['server'] : '';
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

        try {
            if ($action === 'add') {
                $settings->addBlockedServer($server);
            } elseif ($action === 'delete') {
                $settings->removeBlockedServer($server);
            }
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Lista de servidores actualizada.');
    }

    private function instanceAdminActorBlock(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        $settings = new InstanceSettings($this->store, $this->config);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'add';
        $actor = is_string($_POST['actor'] ?? null) ? trim($_POST['actor']) : '';
        $query = is_string($_POST['actor_query'] ?? null) ? trim($_POST['actor_query']) : '';

        try {
            if ($action === 'delete') {
                $settings->removeBlockedActor($actor);
                $this->instanceAdmin('Bloqueo de usuario retirado.');
                return;
            }

            if ($action === 'purge') {
                if (!$settings->isActorBlocked($actor)) {
                    throw new \RuntimeException('Sólo se puede purgar contenido de usuarios bloqueados en todo el servidor.');
                }

                $deleted = $this->purgeActorObjects($actor);
                $this->instanceAdmin('Contenido purgado: ' . $deleted . ' publicaciones.');
                return;
            }

            if ($actor === '' && $query !== '') {
                $resolved = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($query);
                $actor = ActivityPub::objectId($resolved) ?? '';
            }

            $settings->addBlockedActor($actor);
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Usuario bloqueado en todo el servidor.');
    }

    private function purgeActorObjects(string $actorId): int
    {
        if ($actorId === '') {
            throw new \RuntimeException('Actor no válido.');
        }

        $deleted = 0;
        foreach ($this->store->objectFiles() as $file) {
            try {
                $object = $this->store->readJson($file);
            } catch (\Throwable) {
                continue;
            }

            if (ActivityPub::attributedTo($object) !== $actorId) {
                continue;
            }

            $id = ActivityPub::objectId($object);
            if ($id !== null) {
                $this->store->deleteObject($id);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            (new IndexBuilder($this->store))->rebuild();
        }

        return $deleted;
    }

    private function instanceAdminUsers(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $uid = is_string($_POST['uid'] ?? null) ? $_POST['uid'] : '';
        $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

        try {
            if ($action === 'add') {
                $name = is_string($_POST['name'] ?? null) ? $_POST['name'] : '';
                $this->users->create($uid, $name, isset($_POST['admin']));
                $auth->setPassword($uid, $password);
                $this->applyDefaultFollowingToUser($uid);
            } elseif ($action === 'password') {
                if ($password === '') {
                    throw new \RuntimeException('Indica una nueva clave.');
                }
                $auth->setPassword($uid, $password);
            } elseif ($action === 'set-admin' || $action === 'unset-admin') {
                [$currentUid] = $this->requireInstanceAdmin();
                if ($uid === $currentUid) {
                    throw new \RuntimeException('No puedes cambiar tus propios permisos de administrador.');
                }
                $this->users->setAdmin($uid, $action === 'set-admin');
            } elseif ($action === 'delete') {
                [$currentUid] = $this->requireInstanceAdmin();
                if ($uid === $currentUid) {
                    throw new \RuntimeException('No puedes borrar tu propio usuario administrador.');
                }
                $this->users->delete($uid);
                $auth->deleteUser($uid);
            }
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Usuarios actualizados.');
    }

    private function instanceAdminImportUser(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        try {
            $file = $_FILES['archive'] ?? null;
            if (!is_array($file) || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new \RuntimeException('Sube un archivo XML o ZIP válido.');
            }

            $tmp = $file['tmp_name'] ?? '';
            if (!is_string($tmp) || !is_uploaded_file($tmp)) {
                throw new \RuntimeException('No se pudo leer el archivo subido.');
            }

            $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
            $result = $this->archiveService()->importArchive($tmp, $password, true);
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Usuario ' . (string)($result['uid'] ?? '') . ' importado con ' . (string)($result['objects'] ?? 0) . ' posts.');
    }

    private function instanceAdminSocializeUser(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        try {
            $query = is_string($_POST['actor_query'] ?? null) ? trim($_POST['actor_query']) : '';
            if ($query === '') {
                throw new \RuntimeException('Indica un usuario a socializar.');
            }

            $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($query);
            $actorId = ActivityPub::objectId($actor);
            if ($actorId === null) {
                throw new \RuntimeException('Actor no válido.');
            }

            $result = $this->socializeActor($actorId);
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Usuario socializado: ' . (string)($result['followed'] ?? 0) . ' cuentas lo siguen; ' . (string)($result['already'] ?? 0) . ' ya lo seguían.');
    }

    private function instanceAdminDefaultFollowing(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.');
            return;
        }

        try {
            $settings = new InstanceSettings($this->store, $this->config);
            $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

            if ($action === 'add') {
                $query = is_string($_POST['actor_query'] ?? null) ? trim($_POST['actor_query']) : '';
                if ($query === '') {
                    throw new \RuntimeException('Indica un perfil externo.');
                }

                $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($query);
                $actorId = ActivityPub::objectId($actor);
                if ($actorId === null || $this->isLocalActorId($actorId)) {
                    throw new \RuntimeException('Indica un perfil externo válido.');
                }

                $settings->addDefaultFollowingActor($actorId);
            } elseif ($action === 'delete') {
                $actor = is_string($_POST['actor'] ?? null) ? trim($_POST['actor']) : '';
                $settings->removeDefaultFollowingActor($actor);
            } else {
                throw new \RuntimeException('Acción no válida.');
            }
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage());
            return;
        }

        $this->instanceAdmin('Seguidos por defecto actualizados.');
    }

    private function instanceAdminCompileApp(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.', 'app');
            return;
        }

        try {
            $settings = new InstanceSettings($this->store, $this->config);
            $manifest = (new AndroidAppBuilder($this->store, $this->config))->build(
                $settings->instanceName(),
                $settings->faviconPath()
            );
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage(), 'app');
            return;
        }

        $this->instanceAdmin('APK compilado: ' . (string)($manifest['url'] ?? ''), null, 'app');
    }

    private function adminPost(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();
        $admin = new AdminRenderer($this->renderer, $auth);

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $content = $_POST['content'] ?? '';
        $visibility = $_POST['visibility'] ?? 'public';
        $inReplyTo = $_POST['inReplyTo'] ?? null;
        $imageAlt = $_POST['image_alt'] ?? '';

        if ($this->requestBodyExceedsPhpLimit()) {
            echo $this->adminDashboard($uid, $auth, null, 'La publicación supera el límite de subida configurado en PHP (' . ini_get('post_max_size') . ').');
            return;
        }

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($content)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        $duplicateId = $this->recentPostDuplicate(
            $uid,
            $content,
            is_string($visibility) ? $visibility : 'public',
            is_string($inReplyTo) ? $inReplyTo : '',
            is_string($imageAlt) ? $imageAlt : '',
            'image_upload'
        );
        if ($duplicateId !== null) {
            header('Location: ?id=' . rawurlencode($duplicateId));
            return;
        }

        try {
            $attachments = (new MediaUploadService($this->config))->saveImagesFromPost(
                $uid,
                'image_upload',
                $this->attachmentAlts(is_string($imageAlt) ? $imageAlt : '')
            );
            $note = (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->createNote($uid, $content, [
                'visibility' => is_string($visibility) ? $visibility : 'public',
                'inReplyTo' => is_string($inReplyTo) ? $inReplyTo : null,
                'attachments' => $attachments,
            ]);
            $this->rememberRecentPost(
                $uid,
                $content,
                is_string($visibility) ? $visibility : 'public',
                is_string($inReplyTo) ? $inReplyTo : '',
                is_string($imageAlt) ? $imageAlt : '',
                'image_upload',
                (string)$note['id']
            );
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Location: ?id=' . rawurlencode((string)$note['id']));
    }

    private function adminPostEdit(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $id = $_POST['id'] ?? null;
        $content = $_POST['content'] ?? null;
        $imageAlt = $_POST['image_alt'] ?? '';
        $existingAttachmentInput = $_POST['existing_attachment'] ?? [];

        if ($this->requestBodyExceedsPhpLimit()) {
            echo $this->adminDashboard($uid, $auth, null, 'La publicación supera el límite de subida configurado en PHP (' . ini_get('post_max_size') . ').');
            return;
        }

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($id) || !is_string($content)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $alts = $this->attachmentAlts(is_string($imageAlt) ? $imageAlt : '');
            $newAttachments = (new MediaUploadService($this->config))->saveImageSlotsFromPost(
                $uid,
                'image_upload',
                $alts
            );
            $attachments = $this->mergeEditedAttachments(
                is_string($id) ? $id : '',
                is_array($existingAttachmentInput) ? $existingAttachmentInput : [],
                $newAttachments,
                $alts
            );
            $note = (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->updateNote($uid, $id, $content, [
                'attachments' => $attachments,
                'attachments_provided' => true,
            ]);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Location: ?id=' . rawurlencode((string)$note['id']));
    }

    private function adminPostDelete(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $id = $_POST['id'] ?? null;

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($id)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $deleteUid = $uid;
            $note = $this->repo->findByIdOrAlias($id);
            $actor = is_array($note) ? ActivityPub::attributedTo($note) : null;
            $ownerUid = $actor !== null ? $this->localUidForActorId($actor) : null;
            $currentUser = $this->users->find($uid);
            $isInstanceAdmin = is_array($currentUser) && (bool)($currentUser['admin'] ?? false);

            if ($ownerUid !== null && $ownerUid !== $uid && $isInstanceAdmin) {
                $deleteUid = $ownerUid;
            }

            (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->deleteNote($deleteUid, $id);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Location: /');
    }

    private function adminProfile(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $images = new ProfileImageService($this->config);
            $avatar = $images->saveFromPost($uid, 'avatar', 512, 512);
            $header = $images->saveFromPost($uid, 'header', 1500, 500);
            $fields = [
                'name' => $_POST['name'] ?? '',
                'bio' => $_POST['bio'] ?? '',
                'email' => $_POST['email'] ?? '',
                'lang' => is_string($_POST['lang'] ?? null)
                    ? (new LanguageCatalog($this->store, $this->config))->validate($_POST['lang'])
                    : (new LanguageCatalog($this->store, $this->config))->defaultLanguage(),
                'tz' => $_POST['tz'] ?? '',
                'approve_followers' => isset($_POST['approve_followers']),
            ];

            if ($avatar !== null) {
                $fields['avatar'] = $avatar;
            }

            if ($header !== null) {
                $fields['header'] = $header;
            }

            $currentPassword = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
            $newPassword = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
            $newPasswordConfirm = is_string($_POST['new_password_confirm'] ?? null) ? $_POST['new_password_confirm'] : '';
            $changePassword = $currentPassword !== '' || $newPassword !== '' || $newPasswordConfirm !== '';

            if ($changePassword) {
                if ($currentPassword === '' || $newPassword === '' || $newPasswordConfirm === '') {
                    throw new \RuntimeException('Para cambiar la contraseña rellena los tres campos.');
                }

                if (!$auth->verifyPassword($uid, $currentPassword)) {
                    throw new \RuntimeException('La contraseña actual no es correcta.');
                }

                if ($newPassword !== $newPasswordConfirm) {
                    throw new \RuntimeException('La nueva contraseña no coincide.');
                }

                if (strlen($newPassword) < 8) {
                    throw new \RuntimeException('La nueva contraseña debe tener al menos 8 caracteres.');
                }
            }

            $this->users->updateProfile($uid, [
                ...$fields,
            ]);
            (new ActorUpdateService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->enqueue($uid);

            if ($changePassword) {
                $auth->setPassword($uid, $newPassword);
            }
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        echo $this->adminDashboard($uid, $auth, 'Perfil guardado.');
    }

    private function adminExportUser(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        try {
            $zipPath = $this->archiveService()->exportZip($uid);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . rawurlencode($uid) . '-uanna.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        unlink($zipPath);
    }

    private function adminDeleteContent(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $confirm = is_string($_POST['confirm'] ?? null) ? trim($_POST['confirm']) : '';
        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || $confirm !== $uid) {
            echo $this->adminDashboard($uid, $auth, null, 'Para borrar tu contenido escribe tu usuario.');
            return;
        }

        try {
            $deleted = $this->archiveService()->deleteUserContent($uid);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        echo $this->adminDashboard($uid, $auth, 'Contenido borrado: ' . $deleted . ' posts.');
    }

    private function adminDeleteAccount(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $confirm = is_string($_POST['confirm'] ?? null) ? trim($_POST['confirm']) : '';
        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || $confirm !== $uid) {
            echo $this->adminDashboard($uid, $auth, null, 'Para dar de baja tu usuario escribe tu usuario.');
            return;
        }

        try {
            $this->archiveService()->deleteUserAndContent($uid);
            $auth->logout();
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Location: /');
    }

    private function adminReact(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $id = $_POST['id'] ?? null;
        $type = $_POST['type'] ?? null;
        $returnTo = $_POST['return_to'] ?? null;

        if (
            !$auth->checkCsrf(is_string($csrf) ? $csrf : null)
            || !is_string($id)
            || !is_string($type)
        ) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            (new InteractionService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                new ActorRepository($this->store),
                $this->config,
            ))->toggle($uid, $id, $type);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        header('Location: ' . $this->safeReturnLocation(is_string($returnTo) ? $returnTo : null));
    }

    private function safeReturnLocation(?string $location): string
    {
        if ($location === null || $location === '') {
            return $this->homeLocation();
        }

        if (str_contains($location, "\r") || str_contains($location, "\n")) {
            return $this->homeLocation();
        }

        if (str_starts_with($location, '//')) {
            return $this->homeLocation();
        }

        if (str_starts_with($location, '/') || str_starts_with($location, '?')) {
            return $location;
        }

        return $this->homeLocation();
    }

    private function requestBodyExceedsPhpLimit(): bool
    {
        $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (!is_string($contentLength) && !is_int($contentLength)) {
            return false;
        }

        $bytes = (int)$contentLength;
        $limit = $this->iniBytes((string)ini_get('post_max_size'));

        return $bytes > 0 && $limit > 0 && $bytes > $limit && $_POST === [];
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float)$value;

        return match ($unit) {
            'g' => (int)round($number * 1024 * 1024 * 1024),
            'm' => (int)round($number * 1024 * 1024),
            'k' => (int)round($number * 1024),
            default => (int)$number,
        };
    }

    private function adminPrivateMessage(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $to = $_POST['to'] ?? null;
        $content = $_POST['content'] ?? null;

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($to) || !is_string($content)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $recipient = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($to);
            $recipientId = ActivityPub::objectId($recipient);
            if ($recipientId === null) {
                throw new \RuntimeException('No se pudo resolver el destinatario.');
            }

            (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->createNote($uid, $content, [
                'visibility' => 'direct',
                'to' => $recipientId,
            ]);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        echo $this->adminDashboard($uid, $auth, 'Mensaje privado enviado.');
    }

    private function adminConnected(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        echo (new AdminRenderer($this->renderer, $auth))->connectedPage(
            $uid,
            $this->connectedRemoteActors($uid),
            $this->users->all()
        );
    }

    private function connectedRemoteActors(string $viewerUid): array
    {
        $graph = new SocialGraph($this->store);
        $relations = new SocialRelationService($this->store);
        $localActorIds = [];
        foreach ($this->users->all() as $uid => $_user) {
            if (!is_string($uid)) {
                continue;
            }

            $localActorIds[$this->users->actorId($uid)] = true;
            foreach ($this->users->legacyActorIds($uid) as $legacyId) {
                $localActorIds[$legacyId] = true;
            }
        }

        $items = [];
        foreach ($this->users->all() as $localUid => $_user) {
            if (!is_string($localUid)) {
                continue;
            }

            foreach ($graph->following($localUid) as $actor) {
                $actorId = ActivityPub::objectId($actor);
                if ($actorId === null) {
                    continue;
                }

                $isLocal = false;
                foreach (ActivityPub::aliases($actor) as $alias) {
                    if (isset($localActorIds[$alias])) {
                        $isLocal = true;
                        break;
                    }
                }

                if ($isLocal) {
                    continue;
                }

                $key = $actorId;
                $items[$key] ??= [
                    'actor' => $actor,
                    'actor_id' => $actorId,
                    'local_followers' => [],
                    'state' => [],
                ];
                $items[$key]['local_followers'][$localUid] = true;
            }
        }

        foreach ($items as $actorId => &$item) {
            $item['local_followers'] = array_keys($item['local_followers']);
            sort($item['local_followers'], SORT_STRING);
            $state = $relations->state($viewerUid, $actorId);
            $state['following'] = $graph->isFollowing($viewerUid, $actorId);
            $item['state'] = $state;
        }
        unset($item);

        usort($items, static function (array $a, array $b): int {
            $byCount = count($b['local_followers']) <=> count($a['local_followers']);
            if ($byCount !== 0) {
                return $byCount;
            }

            return strcmp((string)($a['actor_id'] ?? ''), (string)($b['actor_id'] ?? ''));
        });

        return array_values($items);
    }

    private function instanceAdminSocialGraph(string $method): void
    {
        [, $auth] = $this->requireInstanceAdmin();
        if ($method !== 'POST' || !$auth->checkCsrf(is_string($_POST['csrf'] ?? null) ? $_POST['csrf'] : null)) {
            $this->instanceAdmin(null, 'Solicitud no válida.', 'social-graph');
            return;
        }

        if (!function_exists('imagecreatetruecolor')) {
            $this->instanceAdmin(null, 'Falta la extensión GD de PHP para generar el grafo social.', 'social-graph');
            return;
        }

        try {
            $dir = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/') . '/assets/instance';
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException('No se pudo crear el directorio de assets.');
            }

            @chmod($dir, 02775);
            $path = $dir . '/social-graph.png';
            $image = $this->buildSocialGraphImage();
            if (!imagepng($image, $path)) {
                imagedestroy($image);
                throw new \RuntimeException('No se pudo guardar el PNG del grafo social.');
            }

            imagedestroy($image);
            @chmod($path, 0664);
        } catch (\Throwable $e) {
            $this->instanceAdmin(null, $e->getMessage(), 'social-graph');
            return;
        }

        $this->instanceAdmin('Grafo social generado.', null, 'social-graph');
    }

    private function buildSocialGraphImage(): \GdImage
    {
        if (!function_exists('imagecreatetruecolor')) {
            throw new \RuntimeException('Falta la extensión GD de PHP.');
        }

        $users = $this->users->all();
        $uids = array_values(array_filter(array_keys($users), 'is_string'));
        sort($uids, SORT_STRING);

        $graph = new SocialGraph($this->store);
        [$nodes, $edges, $localIds] = $this->socialGraphData($uids, $users, $graph);
        $localNodeIds = [];
        $remoteNodeIds = [];
        foreach ($nodes as $nodeId => $node) {
            if ((bool)($node['local'] ?? false)) {
                $localNodeIds[] = $nodeId;
            } else {
                $remoteNodeIds[] = $nodeId;
            }
        }

        $count = max(1, count($nodes));
        $size = max(900, min(2400, 440 + ($count * 34)));
        $center = $size / 2;
        $outerRadius = max(260, ($size / 2) - 132);
        $innerRadius = max(140, $outerRadius * 0.48);

        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        $bg = imagecolorallocate($image, 23, 28, 31);
        $panel = imagecolorallocate($image, 35, 42, 46);
        $text = imagecolorallocate($image, 232, 235, 232);
        $muted = imagecolorallocate($image, 154, 162, 160);
        $followingColor = imagecolorallocate($image, 80, 170, 230);
        $followerColor = imagecolorallocate($image, 232, 95, 86);
        $otherColor = imagecolorallocate($image, 116, 124, 126);
        $nodeBorder = imagecolorallocate($image, 210, 216, 214);
        $remoteBorder = imagecolorallocate($image, 118, 139, 146);

        imagefilledrectangle($image, 0, 0, $size, $size, $bg);
        imagefilledellipse($image, (int)$center, (int)$center, (int)($outerRadius * 2.04), (int)($outerRadius * 2.04), $panel);

        $positions = $this->socialGraphPositions($localNodeIds, $remoteNodeIds, $center, $innerRadius, $outerRadius);

        foreach ($edges as $edge) {
            $from = (string)($edge['from'] ?? '');
            $to = (string)($edge['to'] ?? '');
            if ($from === $to || !isset($positions[$from], $positions[$to])) {
                continue;
            }

            $fromLocal = (bool)($nodes[$from]['local'] ?? false);
            $toLocal = (bool)($nodes[$to]['local'] ?? false);
            $color = $fromLocal && !$toLocal
                ? $followingColor
                : (!$fromLocal && $toLocal ? $followerColor : $otherColor);

            $this->drawArrow($image, $positions[$from], $positions[$to], $color);
        }

        foreach ($nodes as $nodeId => $node) {
            if (!isset($positions[$nodeId])) {
                continue;
            }

            $isLocal = (bool)($node['local'] ?? false);
            $border = $isLocal ? $nodeBorder : $remoteBorder;
            $this->drawGraphNode(
                $image,
                (string)$nodeId,
                (string)($node['label'] ?? $nodeId),
                (string)($node['avatar'] ?? ''),
                $positions[$nodeId],
                $border,
                $text,
                $muted,
                $isLocal ? 58 : 42
            );
        }

        imagestring($image, 5, 24, 22, 'Red de ' . (new InstanceSettings($this->store, $this->config))->instanceName(), $text);
        imagestring($image, 3, 24, 48, 'Anillo interior: usuarios locales | Anillo exterior: usuarios remotos', $muted);
        imagestring($image, 3, 24, 66, 'Azul: local sigue remoto | Rojo: remoto sigue local | Gris: relacion local', $muted);
        $this->drawLegendLine($image, 24, $size - 48, $followingColor, 'Local sigue remoto');
        $this->drawLegendLine($image, 190, $size - 48, $followerColor, 'Remoto sigue local');
        $this->drawLegendLine($image, 372, $size - 48, $otherColor, 'Relacion local');

        return $image;
    }

    private function socialGraphData(array $uids, array $users, SocialGraph $graph): array
    {
        $nodes = [];
        $edges = [];
        $localIds = [];

        foreach ($uids as $uid) {
            $user = is_array($users[$uid] ?? null) ? $users[$uid] : [];
            $nodeId = 'local:' . $uid;
            $nodes[$nodeId] = [
                'label' => (string)($user['name'] ?? $uid),
                'avatar' => $this->users->avatarUrl($user),
                'local' => true,
            ];
            $localIds[$this->users->actorId($uid)] = $nodeId;
            foreach ($this->users->legacyActorIds($uid) as $legacyId) {
                $localIds[$legacyId] = $nodeId;
            }
        }

        foreach ($uids as $uid) {
            $fromNode = 'local:' . $uid;
            foreach ($graph->following($uid) as $actor) {
                $actorId = ActivityPub::objectId($actor);
                if ($actorId === null) {
                    continue;
                }

                $targetNode = $this->socialGraphNodeForActor($actor, $nodes, $localIds);
                if ($targetNode !== '') {
                    $edges[$fromNode . '>' . $targetNode] = ['from' => $fromNode, 'to' => $targetNode];
                }
            }

            foreach ($graph->followers($uid) as $actor) {
                $actorId = ActivityPub::objectId($actor);
                if ($actorId === null) {
                    continue;
                }

                $sourceNode = $this->socialGraphNodeForActor($actor, $nodes, $localIds);
                if ($sourceNode !== '') {
                    $edges[$sourceNode . '>' . $fromNode] = ['from' => $sourceNode, 'to' => $fromNode];
                }
            }
        }

        ksort($nodes, SORT_STRING);
        ksort($edges, SORT_STRING);

        return [$nodes, array_values($edges), $localIds];
    }

    private function socialGraphNodeForActor(array $actor, array &$nodes, array $localIds): string
    {
        foreach (ActivityPub::aliases($actor) as $alias) {
            if (isset($localIds[$alias])) {
                return $localIds[$alias];
            }
        }

        $actorId = ActivityPub::objectId($actor);
        if ($actorId === null) {
            return '';
        }

        $nodeId = 'remote:' . Id::digest($actorId);
        if (!isset($nodes[$nodeId])) {
            $nodes[$nodeId] = [
                'label' => $this->socialGraphActorLabel($actor, $actorId),
                'avatar' => $this->socialGraphActorAvatar($actor),
                'local' => false,
            ];
        }

        return $nodeId;
    }

    private function socialGraphActorLabel(array $actor, string $actorId): string
    {
        $name = (string)($actor['name'] ?? '');
        $preferred = (string)($actor['preferredUsername'] ?? '');
        $host = parse_url($actorId, PHP_URL_HOST);

        if ($preferred !== '' && is_string($host) && $host !== '') {
            return '@' . $preferred . '@' . $host;
        }

        return $name !== '' ? $name : $actorId;
    }

    private function socialGraphActorAvatar(array $actor): string
    {
        $icon = $actor['icon'] ?? null;
        if (is_array($icon) && is_string($icon['url'] ?? null)) {
            return $icon['url'];
        }

        return '';
    }

    private function socialGraphPositions(array $localNodeIds, array $remoteNodeIds, float $center, float $innerRadius, float $outerRadius): array
    {
        $positions = [];
        foreach ([[$localNodeIds, $innerRadius], [$remoteNodeIds, $outerRadius]] as [$nodeIds, $radius]) {
            $count = max(1, count($nodeIds));
            foreach ($nodeIds as $index => $nodeId) {
                $angle = (-M_PI / 2) + (($index / $count) * 2 * M_PI);
                $positions[$nodeId] = [
                    'x' => (int)round($center + cos($angle) * $radius),
                    'y' => (int)round($center + sin($angle) * $radius),
                ];
            }
        }

        return $positions;
    }

    private function drawGraphNode(\GdImage $image, string $uid, string $label, string $avatarUrl, array $position, int $border, int $text, int $muted, int $size = 58): void
    {
        $x = (int)$position['x'];
        $y = (int)$position['y'];
        imagefilledellipse($image, $x, $y, $size + 8, $size + 8, $border);

        $avatar = $this->loadLocalImage($avatarUrl);
        if ($avatar instanceof \GdImage) {
            imagecopyresampled($image, $avatar, (int)($x - ($size / 2)), (int)($y - ($size / 2)), 0, 0, $size, $size, imagesx($avatar), imagesy($avatar));
            imagedestroy($avatar);
        } else {
            $fill = imagecolorallocate($image, 58, 69, 74);
            imagefilledellipse($image, $x, $y, $size, $size, $fill);
            imagestring($image, 5, $x - 5, $y - 7, mb_strtoupper(mb_substr($label !== '' ? $label : $uid, 0, 1)), $text);
        }

        imageellipse($image, $x, $y, $size + 8, $size + 8, $border);
        $display = mb_substr($label !== '' ? $label : $uid, 0, 18);
        $textWidth = imagefontwidth(2) * strlen($display);
        imagestring($image, 2, (int)($x - ($textWidth / 2)), (int)($y + ($size / 2) + 9), $display, $muted);
    }

    private function drawArrow(\GdImage $image, array $from, array $to, int $color): void
    {
        $fromX = (float)$from['x'];
        $fromY = (float)$from['y'];
        $toX = (float)$to['x'];
        $toY = (float)$to['y'];
        $dx = $toX - $fromX;
        $dy = $toY - $fromY;
        $length = max(1.0, sqrt(($dx * $dx) + ($dy * $dy)));
        $offset = 44;
        $startX = $fromX + ($dx / $length) * $offset;
        $startY = $fromY + ($dy / $length) * $offset;
        $endX = $toX - ($dx / $length) * $offset;
        $endY = $toY - ($dy / $length) * $offset;

        imagesetthickness($image, 2);
        imageline($image, (int)$startX, (int)$startY, (int)$endX, (int)$endY, $color);

        $angle = atan2($endY - $startY, $endX - $startX);
        $head = 10;
        $left = $angle + (M_PI * 0.82);
        $right = $angle - (M_PI * 0.82);
        imageline($image, (int)$endX, (int)$endY, (int)($endX + cos($left) * $head), (int)($endY + sin($left) * $head), $color);
        imageline($image, (int)$endX, (int)$endY, (int)($endX + cos($right) * $head), (int)($endY + sin($right) * $head), $color);
        imagesetthickness($image, 1);
    }

    private function drawLegendLine(\GdImage $image, int $x, int $y, int $color, string $label): void
    {
        imageline($image, $x, $y + 7, $x + 32, $y + 7, $color);
        imagestring($image, 3, $x + 40, $y, $label, $color);
    }

    private function loadLocalImage(string $url): ?\GdImage
    {
        foreach ($this->localImageCandidates($url) as $candidate) {
            $image = $this->imageFromFile($candidate);
            if ($image instanceof \GdImage) {
                return $image;
            }
        }

        return $this->remoteImage($url);
    }

    private function localImageCandidates(string $url): array
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return [];
        }

        $basePath = parse_url((string)$this->config['base_url'], PHP_URL_PATH);
        $basePath = is_string($basePath) ? rtrim($basePath, '/') : '';
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }

        $publicDir = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/');
        $dataDir = rtrim((string)$this->config['data_dir'], '/');
        $candidates = [
            $publicDir . '/' . ltrim($path, '/'),
            $dataDir . '/' . ltrim($path, '/'),
        ];

        if (preg_match('#^/([^/]+)/s/([^/]+)$#', $path, $match) === 1) {
            $uid = rawurldecode($match[1]);
            $file = rawurldecode($match[2]);
            if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $uid) === 1 && basename($file) === $file) {
                $candidates[] = $dataDir . '/media/' . $uid . '/' . $file;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function imageFromFile(string $path): ?\GdImage
    {
        if (!is_file($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        return $image instanceof \GdImage ? $image : null;
    }

    private function remoteImage(string $url): ?\GdImage
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $localHost = parse_url((string)$this->config['base_url'], PHP_URL_HOST);
        if (is_string($host) && is_string($localHost) && strcasecmp($host, $localHost) === 0) {
            return null;
        }

        $dir = rtrim((string)$this->config['data_dir'], '/') . '/cache/social-graph-avatars';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        @chmod($dir, 02775);
        $path = $dir . '/' . Id::digest($url) . '.img';
        if (!is_file($path) || filemtime($path) < time() - 86400) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 4,
                    'ignore_errors' => true,
                    'header' => "Accept: image/*\r\nUser-Agent: Uanna social graph\r\n",
                ],
            ]);
            $handle = @fopen($url, 'rb', false, $context);
            if (!is_resource($handle)) {
                return null;
            }

            $bytes = stream_get_contents($handle, 1024 * 1024);
            fclose($handle);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }

            $probe = @imagecreatefromstring($bytes);
            if (!$probe instanceof \GdImage) {
                return null;
            }

            imagedestroy($probe);

            file_put_contents($path, $bytes);
            @chmod($path, 0664);
        }

        return $this->imageFromFile($path);
    }

    private function adminSocial(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $actorId = $_POST['actor'] ?? null;
        $actorQuery = $_POST['actor_query'] ?? null;
        $action = $_POST['action'] ?? null;
        $returnTo = $_POST['return_to'] ?? null;

        if (
            !$auth->checkCsrf(is_string($csrf) ? $csrf : null)
            || !is_string($action)
        ) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            if ($action === 'follow-new') {
                if (!is_string($actorQuery) || trim($actorQuery) === '') {
                    throw new \RuntimeException('Indica un usuario a seguir.');
                }

                $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($actorQuery);
                $actorId = ActivityPub::objectId($actor);
            }

            if (!is_string($actorId) || $actorId === '') {
                throw new \RuntimeException('Actor no válido.');
            }

            $message = $this->applySocialAction($uid, $actorId, $action);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        if (is_string($returnTo) && $returnTo !== '') {
            header('Location: ' . $this->safeReturnLocation($returnTo));
            return;
        }

        echo $this->adminDashboard($uid, $auth, $message);
    }


    private function adminModerationFollow(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();
        $admin = new AdminRenderer($this->renderer, $auth);

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $caseId = $_POST['case'] ?? null;
        $decision = $_POST['decision'] ?? null;

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($caseId) || !is_string($decision)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            if ($decision === 'approve') {
                $result = $this->moderation()->approveFollow($uid, $caseId, $uid);
            } elseif ($decision === 'reject') {
                $result = $this->moderation()->rejectFollow($uid, $caseId, $uid);
            } else {
                throw new \RuntimeException('Decisión no válida.');
            }
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        $message = $result['status'] === 'approved' ? 'Solicitud aprobada.' : 'Solicitud rechazada.';
        echo $this->adminDashboard($uid, $auth, $message);
    }

    private function adminModerationCreate(string $method): void
    {
        $auth = $this->auth ?? new Auth($this->store);
        $uid = $auth->currentUser();
        $admin = new AdminRenderer($this->renderer, $auth);

        if ($uid === null) {
            header('Location: ?route=admin/login');
            return;
        }

        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $csrf = $_POST['csrf'] ?? null;
        $caseId = $_POST['case'] ?? null;
        $decision = $_POST['decision'] ?? null;

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($caseId) || !is_string($decision)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            if ($decision === 'approve') {
                $result = $this->moderation()->approveCreate($uid, $caseId, $uid);
            } elseif ($decision === 'reject') {
                $result = $this->moderation()->rejectCreate($uid, $caseId, $uid);
            } else {
                throw new \RuntimeException('Decisión no válida.');
            }
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        $message = $result['status'] === 'approved' ? 'Publicación aprobada.' : 'Publicación rechazada.';
        echo $this->adminDashboard($uid, $auth, $message);
    }

    private function moderation(): ModerationService
    {
        return new ModerationService(
            $this->store,
            $this->users,
            new ActorRepository($this->store),
            new SocialGraph($this->store),
            new FileQueue($this->store),
        );
    }

    private function archiveService(): UserArchiveService
    {
        return new UserArchiveService($this->store, $this->users, $this->config);
    }

    private function pendingModeration(string $uid): array
    {
        $moderation = $this->moderation();

        return [
            $moderation->pending($uid, 'follows'),
            $moderation->pending($uid, 'creates'),
        ];
    }

    private function applySocialAction(string $uid, string $actorId, string $action): string
    {
        $graph = new SocialGraph($this->store);
        $relations = new SocialRelationService($this->store);

        return match ($action) {
            'follow-new' => $this->followActor($uid, $actorId, $graph),
            'follow' => $this->followActor($uid, $actorId, $graph),
            'unfollow' => $this->unfollowActor($uid, $actorId, $graph),
            'mute' => $this->setActorMuted($uid, $actorId, $relations, true),
            'unmute' => $this->setActorMuted($uid, $actorId, $relations, false),
            'block' => $this->setActorBlocked($uid, $actorId, $relations, true),
            'unblock' => $this->setActorBlocked($uid, $actorId, $relations, false),
            default => throw new \RuntimeException('Acción no válida.'),
        };
    }

    private function socializeActor(string $actorId): array
    {
        $graph = new SocialGraph($this->store);
        $localTargetUid = $this->localUidForActorId($actorId);
        $followed = 0;
        $already = 0;
        $skipped = 0;

        foreach ($this->users->all() as $uid => $_user) {
            $uid = (string)$uid;
            if ($localTargetUid !== null && $uid === $localTargetUid) {
                $skipped++;
                continue;
            }

            if ($graph->isFollowing($uid, $actorId)) {
                $already++;
                continue;
            }

            $this->followActor($uid, $actorId, $graph);
            $followed++;
        }

        return [
            'followed' => $followed,
            'already' => $already,
            'skipped' => $skipped,
        ];
    }

    private function applyDefaultFollowingToUser(string $uid): array
    {
        if ($this->users->find($uid) === null) {
            return [
                'followed' => 0,
                'already' => 0,
                'skipped' => 0,
            ];
        }

        $graph = new SocialGraph($this->store);
        $followed = 0;
        $already = 0;
        $skipped = 0;

        foreach ((new InstanceSettings($this->store, $this->config))->defaultFollowingActors() as $actorId) {
            if ($this->isLocalActorId($actorId)) {
                $skipped++;
                continue;
            }

            if ($graph->isFollowing($uid, $actorId)) {
                $already++;
                continue;
            }

            $this->followActor($uid, $actorId, $graph);
            $followed++;
        }

        return [
            'followed' => $followed,
            'already' => $already,
            'skipped' => $skipped,
        ];
    }

    private function followActor(string $uid, string $actorId, SocialGraph $graph): string
    {
        if ($graph->isFollowing($uid, $actorId)) {
            if ($this->queueFollowDelivery($uid, $actorId, $this->actorForSocialAction($uid, $actorId))) {
                return 'Ya sigues a ese usuario. Se ha reenviado el Follow federado.';
            }

            return 'Ya sigues a ese usuario.';
        }

        $actor = $this->actorForSocialAction($uid, $actorId);
        $graph->addFollowing($uid, $actor);

        $this->queueFollowDelivery($uid, $actorId, $actor);

        return 'Usuario añadido a seguidos.';
    }

    private function queueFollowDelivery(string $uid, string $actorId, array $actor): bool
    {
        $inbox = (new SocialGraph($this->store))->inboxForActor($actor);
        if ($inbox !== null && !$this->isLocalActorId($actorId)) {
            $localActor = $this->users->actorId($uid);
            $activity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActor . '#follow-' . Id::digest($actorId) . '-' . gmdate('YmdHis'),
                'type' => 'Follow',
                'actor' => $localActor,
                'object' => $actorId,
                'to' => [$actorId],
                'published' => gmdate('c'),
            ];

            (new FileQueue($this->store))->enqueue('deliver', [
                'actor' => $localActor,
                'inbox' => $inbox,
                'activity' => $activity,
            ]);

            return true;
        }

        return false;
    }

    private function unfollowActor(string $uid, string $actorId, SocialGraph $graph): string
    {
        if (!$graph->isFollowing($uid, $actorId)) {
            return 'No sigues a ese usuario.';
        }

        $actor = $this->actorForSocialAction($uid, $actorId);
        $graph->removeFollowing($uid, $actorId);
        (new SocialRelationService($this->store))->setMuted($uid, $actorId, false);

        $inbox = $graph->inboxForActor($actor);
        if ($inbox !== null && !$this->isLocalActorId($actorId)) {
            $localActor = $this->users->actorId($uid);
            $follow = [
                'id' => $localActor . '#follow-' . Id::digest($actorId),
                'type' => 'Follow',
                'actor' => $localActor,
                'object' => $actorId,
            ];
            $activity = [
                '@context' => 'https://www.w3.org/ns/activitystreams',
                'id' => $localActor . '#undo-follow-' . Id::digest($actorId) . '-' . gmdate('YmdHis'),
                'type' => 'Undo',
                'actor' => $localActor,
                'object' => $follow,
                'to' => [$actorId],
                'published' => gmdate('c'),
            ];

            (new FileQueue($this->store))->enqueue('deliver', [
                'actor' => $localActor,
                'inbox' => $inbox,
                'activity' => $activity,
            ]);
        }

        return 'Has dejado de seguir a ese usuario.';
    }

    private function setActorMuted(string $uid, string $actorId, SocialRelationService $relations, bool $muted): string
    {
        if (!(new SocialGraph($this->store))->isFollowing($uid, $actorId)) {
            throw new \RuntimeException('Sólo puedes silenciar a usuarios que sigues.');
        }

        $relations->setMuted($uid, $actorId, $muted);
        return $muted ? 'Usuario silenciado.' : 'Silencio retirado.';
    }

    private function setActorBlocked(string $uid, string $actorId, SocialRelationService $relations, bool $blocked): string
    {
        $relations->setBlocked($uid, $actorId, $blocked);
        if ($blocked) {
            (new InstanceSettings($this->store, $this->config))->recordUserBlock($uid, $actorId);
        }
        return $blocked ? 'Usuario bloqueado.' : 'Usuario desbloqueado.';
    }

    private function actorForSocialAction(string $uid, string $actorId): array
    {
        foreach ([(new SocialGraph($this->store))->followers($uid), (new SocialGraph($this->store))->following($uid)] as $list) {
            foreach ($list as $actor) {
                if (is_array($actor) && in_array($actorId, ActivityPub::aliases($actor), true)) {
                    return $actor;
                }
            }
        }

        foreach ($this->users->all() as $localUid => $user) {
            $ids = array_merge([$this->users->actorId((string)$localUid)], $this->users->legacyActorIds((string)$localUid));
            if (in_array($actorId, $ids, true)) {
                return $this->users->activityPubActor((string)$localUid, is_array($user) ? $user : []);
            }
        }

        $actor = (new ActorRepository($this->store))->findById($actorId);
        if ($actor !== null) {
            return $actor;
        }

        throw new \RuntimeException('No encuentro ese actor en la caché local.');
    }

    private function socialStates(string $uid): array
    {
        $graph = new SocialGraph($this->store);
        $relations = new SocialRelationService($this->store);
        $states = [];

        foreach (array_merge($graph->followers($uid), $graph->following($uid)) as $actor) {
            if (!is_array($actor)) {
                continue;
            }

            $actorId = ActivityPub::objectId($actor);
            if ($actorId === null) {
                continue;
            }

            $state = $relations->state($uid, $actorId);
            $state['following'] = $graph->isFollowing($uid, $actorId);
            $states[$actorId] = $state;
        }

        return $states;
    }

    private function isLocalActorId(string $actorId): bool
    {
        return $this->localUidForActorId($actorId) !== null;
    }

    private function localUidForActorId(string $actorId): ?string
    {
        foreach ($this->users->all() as $uid => $_user) {
            $uid = (string)$uid;
            $ids = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
            if (in_array($actorId, $ids, true)) {
                return $uid;
            }
        }

        return null;
    }

    private function adminDashboard(string $uid, Auth $auth, ?string $message = null, ?string $error = null): string
    {
        $focus = $_GET['focus'] ?? '';
        $section = $_GET['section'] ?? '';
        if ($focus === 'notifications' || $section === 'notifications') {
            $this->markNotificationsSeen($uid);
        }

        [$pendingFollows, $pendingCreates] = $this->pendingModeration($uid);
        $timelineSearchQuery = $_GET['timeline_q'] ?? '';
        $timelineSearchQuery = is_string($timelineSearchQuery) ? trim($timelineSearchQuery) : '';
        $timeline = $this->privateTimeline($uid);
        $timelineSearchScope = $timelineSearchQuery !== '' ? $this->privateTimeline($uid, $this->timelineSearchLimit()) : [];
        $timelineSearchActions = [
            'uid' => $uid,
            'is_admin' => is_array($this->users->find($uid)) && (bool)($this->users->find($uid)['admin'] ?? false),
            'csrf' => $auth->csrfToken(),
        ];
        $timelineSearchResults = $timelineSearchQuery !== ''
            ? $this->renderer->threadedObjectList($this->searchTimeline($timelineSearchScope, $timelineSearchQuery), $timelineSearchActions)
            : '';
        $settings = new InstanceSettings($this->store, $this->config);
        $socialGraphPath = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/') . '/assets/instance/social-graph.png';
        $socialGraphUrl = is_file($socialGraphPath)
            ? $this->publicAssetUrl('assets/instance/social-graph.png') . '?v=' . (string)filemtime($socialGraphPath)
            : '';
        $socialGraphDate = is_file($socialGraphPath)
            ? DateFormat::human(gmdate('c', (int)filemtime($socialGraphPath)), (string)($this->config['timezone'] ?? 'Europe/Madrid'))
            : '';

        return (new AdminRenderer($this->renderer, $auth))->dashboard(
            $uid,
            $pendingFollows,
            $pendingCreates,
            $message,
            $error,
            $timeline,
            $this->users->find($uid),
            $this->users->webUrl($uid),
            $this->latestNotifications($uid),
            (new SocialGraph($this->store))->followers($uid),
            (new SocialGraph($this->store))->following($uid),
            $this->latestPrivateMessages($uid),
            $this->socialStates($uid),
            $timelineSearchQuery,
            $timelineSearchResults,
            (new AndroidAppBuilder($this->store, $this->config))->manifest(),
            (new LanguageCatalog($this->store, $this->config))->available(),
            $settings->instanceName(),
            $socialGraphUrl,
            $socialGraphDate,
        );
    }

    private function markNotificationsSeen(string $uid): void
    {
        $this->store->writeJson($this->store->dataDir() . '/state/users/' . rawurlencode($uid) . '/notifications.json', [
            'seen_at' => gmdate('c'),
        ]);
    }

    private function searchTimeline(array $timeline, string $query): array
    {
        $query = mb_strtolower(trim($query));
        if ($query === '') {
            return [];
        }

        $results = [];
        foreach ($timeline as $object) {
            if (!is_array($object)) {
                continue;
            }

            $haystack = mb_strtolower($this->objectSearchText($object));
            if ($haystack !== '' && str_contains($haystack, $query)) {
                $results[] = $object;
            }
        }

        return array_slice($results, 0, 40);
    }

    private function objectSearchText(array $object): string
    {
        $parts = [];
        foreach (['name', 'summary', 'content', 'sourceContent', 'url', 'id'] as $field) {
            $value = $object[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        }

        return trim(preg_replace('/\s+/u', ' ', strip_tags(implode(' ', $parts))) ?? '');
    }

    private function timelineSearchLimit(): int
    {
        return max(80, min(20000, (int)($this->config['timeline_search_limit'] ?? 5000)));
    }

    private function timelineMore(string $method): void
    {
        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        $scope = $_GET['scope'] ?? '';
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $limit = $this->timelinePageSize();
        $actions = $this->currentActions();

        if ($scope === 'public') {
            Http::json($this->renderer->publicTimelineChunk($offset, $limit));
            return;
        }

        if ($scope === 'private') {
            $auth = $this->auth ?? new Auth($this->store);
            $uid = $auth->currentUser();
            if ($uid === null) {
                Http::json(['error' => 'unauthorized'], 'application/json', 401);
                return;
            }

            $objects = $this->privateTimeline($uid, $limit + 1, $offset);
            $hasMore = count($objects) > $limit;
            $objects = array_slice($objects, 0, $limit);
            Http::json([
                'html' => $this->renderer->timelineChunk($objects, [
                    'uid' => $uid,
                    'is_admin' => is_array($this->users->find($uid)) && (bool)($this->users->find($uid)['admin'] ?? false),
                    'csrf' => $auth->csrfToken(),
                ]),
                'next' => $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'private', 'offset' => $offset + $limit]) : '',
            ]);
            return;
        }

        if ($scope === 'user') {
            $uid = $_GET['user'] ?? '';
            if (!is_string($uid) || $uid === '' || $this->users->find($uid) === null) {
                Http::notFound();
                return;
            }

            Http::json($this->renderer->userTimelineChunk($uid, $actions, $offset, $limit));
            return;
        }

        if ($scope === 'actor') {
            $actor = $_GET['actor'] ?? '';
            if (!is_string($actor) || $actor === '') {
                Http::notFound();
                return;
            }

            Http::json($this->renderer->actorTimelineChunk($actor, $actions, $offset, $limit));
            return;
        }

        if ($scope === 'tag') {
            $tag = $_GET['tag'] ?? '';
            if (!is_string($tag) || trim($tag) === '') {
                Http::notFound();
                return;
            }

            $tag = $this->normalizeTag($tag);
            $objects = $this->tagObjects($tag, $offset, $limit + 1);
            $hasMore = count($objects) > $limit;
            $objects = array_slice($objects, 0, $limit);
            Http::json([
                'html' => $this->renderer->timelineChunk($objects, $actions),
                'next' => $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'tag', 'tag' => $tag, 'offset' => $offset + $limit]) : '',
            ]);
            return;
        }

        Http::notFound();
    }

    private function api(string $route, string $method): void
    {
        try {
            $uid = $this->apiAuthenticatedUid();
        } catch (\Throwable) {
            header('WWW-Authenticate: Basic realm="Uanna API"');
            Http::json(['error' => 'unauthorized'], 'application/json', 401);
            return;
        }

        $path = trim(substr($route, 3), '/');

        try {
            if ($path === '') {
                $this->apiIndex($uid);
                return;
            }

            if ($path === 'me') {
                if ($method !== 'GET') {
                    Http::methodNotAllowed();
                    return;
                }

                Http::json(['user' => $this->apiUser($uid)]);
                return;
            }

            if ($path === 'timeline') {
                $this->apiTimeline($uid, $method);
                return;
            }

            if ($path === 'post') {
                $this->apiPost($uid, $method);
                return;
            }

            if ($path === 'reply') {
                $this->apiReply($uid, $method);
                return;
            }

            if ($path === 'follow') {
                $this->apiFollow($uid, $method);
                return;
            }

            if ($path === 'unfollow') {
                $this->apiUnfollow($uid, $method);
                return;
            }

            if ($path === 'followers') {
                $this->apiSocialCollection($uid, $method, 'followers');
                return;
            }

            if ($path === 'following') {
                $this->apiSocialCollection($uid, $method, 'following');
                return;
            }

            if ($path === 'reaction') {
                $this->apiReaction($uid, $method);
                return;
            }

            if ($path === 'thread') {
                $this->apiThread($uid, $method);
                return;
            }
        } catch (\InvalidArgumentException $e) {
            Http::json(['error' => 'bad_request', 'message' => $e->getMessage()], 'application/json', 400);
            return;
        } catch (\Throwable $e) {
            Http::json(['error' => 'server_error', 'message' => $e->getMessage()], 'application/json', 500);
            return;
        }

        Http::notFound();
    }

    private function apiIndex(string $uid): void
    {
        Http::json([
            'user' => $this->apiUser($uid),
            'endpoints' => [
                'GET ?route=api/me',
                'GET ?route=api/timeline&scope=home|public|user|actor&limit=20&offset=0',
                'GET ?route=api/post&id=https://...',
                'GET ?route=api/thread&id=https://...',
                'POST ?route=api/post',
                'POST ?route=api/reply',
                'POST ?route=api/follow',
                'POST ?route=api/unfollow',
                'GET ?route=api/following&user=david&limit=20&offset=0',
                'GET ?route=api/followers&user=david&limit=20&offset=0',
                'GET ?route=api/reaction&id=https://...',
                'POST ?route=api/reaction',
                'DELETE ?route=api/reaction&id=https://...&type=Like|Announce',
                'PATCH ?route=api/post',
                'DELETE ?route=api/post&id=https://...',
            ],
        ]);
    }

    private function apiTimeline(string $uid, string $method): void
    {
        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        $scope = is_string($_GET['scope'] ?? null) ? (string)$_GET['scope'] : 'home';
        $limit = $this->apiLimit();
        $offset = $this->apiOffset();

        if ($scope === 'home' || $scope === 'private') {
            $objects = $this->privateTimeline($uid, $limit, $offset);
        } elseif ($scope === 'public') {
            $objects = $this->apiPublicTimeline($limit, $offset);
        } elseif ($scope === 'user') {
            $targetUid = is_string($_GET['user'] ?? null) ? (string)$_GET['user'] : '';
            if ($targetUid === '' || $this->users->find($targetUid) === null) {
                Http::notFound();
                return;
            }

            $objects = $this->apiActorTimeline($uid, array_merge([$this->users->actorId($targetUid)], $this->users->legacyActorIds($targetUid)), $limit, $offset);
        } elseif ($scope === 'actor') {
            $actor = is_string($_GET['actor'] ?? null) ? (string)$_GET['actor'] : '';
            if ($actor === '') {
                throw new \InvalidArgumentException('Falta actor.');
            }

            $objects = $this->apiActorTimeline($uid, [$actor], $limit, $offset);
        } else {
            throw new \InvalidArgumentException('Scope no soportado.');
        }

        $objects = array_values(array_filter($objects, fn (array $object): bool => $this->apiCanReadObject($uid, $object)));

        Http::json([
            'scope' => $scope,
            'limit' => $limit,
            'offset' => $offset,
            'items' => array_map(fn (array $object): array => $this->apiObject($uid, $object), $objects),
        ]);
    }

    private function apiPost(string $uid, string $method): void
    {
        if ($method === 'GET') {
            $id = $this->apiRequiredId();
            $object = $this->repo->findByIdOrAlias($id);
            if ($object === null || !$this->apiCanReadObject($uid, $object)) {
                Http::notFound();
                return;
            }

            Http::json(['post' => $this->apiObject($uid, $object)]);
            return;
        }

        if ($method === 'POST') {
            $input = $this->apiJsonBody();
            $note = $this->apiPostService()->createNote($uid, $this->apiString($input, 'content'), [
                'visibility' => $this->apiVisibility($input['visibility'] ?? 'public'),
                'inReplyTo' => is_string($input['inReplyTo'] ?? null) ? $input['inReplyTo'] : null,
                'to' => is_string($input['to'] ?? null) ? $input['to'] : null,
            ]);

            Http::json(['post' => $this->apiObject($uid, $note)], 'application/json', 201);
            return;
        }

        if ($method === 'PATCH') {
            $input = $this->apiJsonBody();
            $note = $this->apiPostService()->updateNote($uid, $this->apiString($input, 'id'), $this->apiString($input, 'content'));
            Http::json(['post' => $this->apiObject($uid, $note)]);
            return;
        }

        if ($method === 'DELETE') {
            $this->apiPostService()->deleteNote($uid, $this->apiRequiredId());
            Http::json(['ok' => true]);
            return;
        }

        Http::methodNotAllowed();
    }

    private function apiReply(string $uid, string $method): void
    {
        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $input = $this->apiJsonBody();
        $inReplyTo = $this->apiString($input, 'inReplyTo');
        $parent = $this->repo->findByIdOrAlias($inReplyTo);
        if ($parent === null || !$this->apiCanReadObject($uid, $parent)) {
            Http::notFound();
            return;
        }

        $note = $this->apiPostService()->createNote($uid, $this->apiString($input, 'content'), [
            'visibility' => $this->apiVisibility($input['visibility'] ?? 'public'),
            'inReplyTo' => $inReplyTo,
        ]);

        Http::json(['post' => $this->apiObject($uid, $note)], 'application/json', 201);
    }

    private function apiFollow(string $uid, string $method): void
    {
        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $actorId = $this->apiResolveActorInput($this->apiJsonBody());
        $graph = new SocialGraph($this->store);
        $alreadyFollowing = $graph->isFollowing($uid, $actorId);
        $message = $this->followActor($uid, $actorId, $graph);

        Http::json([
            'ok' => true,
            'actor' => $this->apiActor($actorId),
            'following' => true,
            'already_following' => $alreadyFollowing,
            'message' => $message,
        ], 'application/json', $alreadyFollowing ? 200 : 201);
    }

    private function apiUnfollow(string $uid, string $method): void
    {
        if ($method !== 'POST') {
            Http::methodNotAllowed();
            return;
        }

        $actorId = $this->apiResolveActorInput($this->apiJsonBody());
        $graph = new SocialGraph($this->store);
        $wasFollowing = $graph->isFollowing($uid, $actorId);
        $message = $this->unfollowActor($uid, $actorId, $graph);

        Http::json([
            'ok' => true,
            'actor' => $this->apiActor($actorId),
            'following' => false,
            'was_following' => $wasFollowing,
            'message' => $message,
        ]);
    }

    private function apiSocialCollection(string $uid, string $method, string $kind): void
    {
        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        $targetUid = is_string($_GET['user'] ?? null) && trim((string)$_GET['user']) !== ''
            ? trim((string)$_GET['user'])
            : $uid;

        if ($this->users->find($targetUid) === null) {
            Http::notFound();
            return;
        }

        $graph = new SocialGraph($this->store);
        if (!$this->apiCanReadSocialCollection($uid, $targetUid, $graph)) {
            Http::forbidden();
            return;
        }

        $actors = $kind === 'followers' ? $graph->followers($targetUid) : $graph->following($targetUid);
        $actorIds = [];

        foreach ($actors as $actor) {
            $actorId = ActivityPub::objectId($actor);
            if ($actorId !== null) {
                $actorIds[] = $actorId;
            }
        }

        $actorIds = array_values(array_unique($actorIds));
        sort($actorIds, SORT_STRING);

        $limit = $this->apiLimit();
        $offset = $this->apiOffset();
        $pageIds = array_slice($actorIds, $offset, $limit);

        Http::json([
            'collection' => $kind,
            'user' => $targetUid,
            'total' => count($actorIds),
            'limit' => $limit,
            'offset' => $offset,
            'items' => array_map(fn (string $actorId): array => $this->apiActor($actorId), $pageIds),
        ]);
    }

    private function apiCanReadSocialCollection(string $uid, string $targetUid, SocialGraph $graph): bool
    {
        if ($uid === $targetUid || (bool)($this->config['expose_social_graph'] ?? false)) {
            return true;
        }

        $user = $this->users->find($uid);
        if (is_array($user) && (bool)($user['admin'] ?? false)) {
            return true;
        }

        foreach (array_merge([$this->users->actorId($targetUid)], $this->users->legacyActorIds($targetUid)) as $actorId) {
            if ($graph->isFollowing($uid, $actorId)) {
                return true;
            }
        }

        return false;
    }

    private function apiResolveActorInput(array $input): string
    {
        $actorId = is_string($input['actor'] ?? null) ? trim($input['actor']) : '';
        $query = is_string($input['actor_query'] ?? null) ? trim($input['actor_query']) : '';

        if ($actorId === '' && $query === '') {
            throw new \InvalidArgumentException('Falta actor o actor_query.');
        }

        if ($query !== '') {
            $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($query);
            $actorId = ActivityPub::objectId($actor) ?? '';
        } elseif ((new ActorRepository($this->store))->findById($actorId) === null && !$this->isLocalActorId($actorId) && str_starts_with($actorId, 'https://')) {
            $actor = (new RemoteActorResolver($this->store, $this->users, $this->config))->resolve($actorId);
            $actorId = ActivityPub::objectId($actor) ?? $actorId;
        }

        if ($actorId === '') {
            throw new \InvalidArgumentException('Actor no válido.');
        }

        return $actorId;
    }

    private function apiReaction(string $uid, string $method): void
    {
        if ($method === 'POST') {
            $input = $this->apiJsonBody();
            $id = $this->apiString($input, 'id');
            $type = $this->apiReactionType($input['type'] ?? null);
            $object = $this->repo->findByIdOrAlias($id);
            if ($object === null || !$this->apiCanReadObject($uid, $object)) {
                Http::notFound();
                return;
            }

            Http::json($this->apiInteractionService()->react($uid, $id, $type), 'application/json', 201);
            return;
        }

        if ($method === 'DELETE') {
            $id = $this->apiRequiredId();
            $type = $this->apiReactionType($_GET['type'] ?? null);
            $object = $this->repo->findByIdOrAlias($id);
            if ($object === null || !$this->apiCanReadObject($uid, $object)) {
                Http::notFound();
                return;
            }

            Http::json($this->apiInteractionService()->undo($uid, $id, $type));
            return;
        }

        if ($method === 'GET') {
            $id = $this->apiRequiredId();
            $object = $this->repo->findByIdOrAlias($id);
            if ($object === null || !$this->apiCanReadObject($uid, $object)) {
                Http::notFound();
                return;
            }

            Http::json($this->apiInteractionService()->actors($object));
            return;
        }

        Http::methodNotAllowed();
    }

    private function apiThread(string $uid, string $method): void
    {
        if ($method !== 'GET') {
            Http::methodNotAllowed();
            return;
        }

        $id = $this->apiRequiredId();
        $object = $this->repo->findByIdOrAlias($id);
        if ($object === null || !$this->apiCanReadObject($uid, $object)) {
            Http::notFound();
            return;
        }

        Http::json(['thread' => $this->apiThreadNode($uid, $object)]);
    }

    private function apiAuthenticatedUid(): string
    {
        $uid = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ((!is_string($uid) || !is_string($password)) && is_string($_SERVER['HTTP_AUTHORIZATION'] ?? null)) {
            $authorization = (string)$_SERVER['HTTP_AUTHORIZATION'];
            if (preg_match('/^Basic\s+(.+)$/i', $authorization, $match)) {
                $decoded = base64_decode($match[1], true);
                if (is_string($decoded) && str_contains($decoded, ':')) {
                    [$uid, $password] = explode(':', $decoded, 2);
                }
            }
        }

        $auth = $this->auth ?? new Auth($this->store);
        if (!is_string($uid) || !is_string($password) || !$auth->verifyPassword($uid, $password) || $this->users->find($uid) === null) {
            throw new \RuntimeException('unauthorized');
        }

        return $uid;
    }

    private function apiPostService(): PostService
    {
        return new PostService(
            $this->store,
            $this->users,
            new FileQueue($this->store),
            new SocialGraph($this->store),
            $this->config,
        );
    }

    private function apiInteractionService(): InteractionService
    {
        return $this->apiInteractionService ??= new InteractionService(
            $this->store,
            $this->users,
            new FileQueue($this->store),
            new SocialGraph($this->store),
            new ActorRepository($this->store),
            $this->config,
        );
    }

    private function apiJsonBody(): array
    {
        $body = file_get_contents('php://input');
        if (!is_string($body) || trim($body) === '') {
            throw new \InvalidArgumentException('El cuerpo JSON está vacío.');
        }

        try {
            $data = Json::decode($body, 'api request');
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        if (!is_array($data)) {
            throw new \InvalidArgumentException('El cuerpo JSON debe ser un objeto.');
        }

        return $data;
    }

    private function apiString(array $input, string $field): string
    {
        $value = $input[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException('Falta ' . $field . '.');
        }

        return trim($value);
    }

    private function apiVisibility(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return 'public';
        }

        if (!in_array($value, ['public', 'followers', 'direct'], true)) {
            throw new \InvalidArgumentException('Visibilidad no soportada.');
        }

        return $value;
    }

    private function apiReactionType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['Like', 'Announce'], true)) {
            throw new \InvalidArgumentException('Tipo de reacción no soportado.');
        }

        return $value;
    }

    private function apiRequiredId(): string
    {
        $id = $_GET['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new \InvalidArgumentException('Falta id.');
        }

        return trim($id);
    }

    private function apiLimit(): int
    {
        return max(1, min(100, (int)($_GET['limit'] ?? 20)));
    }

    private function apiOffset(): int
    {
        return max(0, (int)($_GET['offset'] ?? 0));
    }

    private function apiPublicTimeline(int $limit, int $offset): array
    {
        $objects = array_values(array_filter(
            $this->repo->recent($offset + $limit),
            fn (array $object): bool => ActivityPub::isPublicObject($object) && !$this->objectBlocked($object)
        ));

        return array_slice($objects, $offset, $limit);
    }

    private function apiActorTimeline(string $uid, array $actorIds, int $limit, int $offset): array
    {
        $objects = $this->repo->byAnyActor($actorIds, $offset + $limit);
        $objects = array_values(array_filter($objects, fn (array $object): bool => $this->apiCanReadObject($uid, $object)));

        return array_slice($objects, $offset, $limit);
    }

    private function apiCanReadObject(string $uid, array $object): bool
    {
        if ($this->objectBlocked($object)) {
            return false;
        }

        if (ActivityPub::isPublicObject($object)) {
            return true;
        }

        if ($this->apiCanEditObject($uid, $object)) {
            return true;
        }

        $localIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
        $audience = ActivityPub::audience($object);
        foreach ($localIds as $actorId) {
            if (in_array($actorId, $audience, true)) {
                return true;
            }
        }

        $receivedBy = $object['_oannes_inbox_uids'] ?? [];
        if (is_array($receivedBy) && in_array($uid, $receivedBy, true)) {
            return true;
        }

        $actor = ActivityPub::attributedTo($object);
        if ($actor !== null && $this->isLocalActorId($actor)) {
            return false;
        }

        return $this->objectVisibleInUserTimeline($uid, $object);
    }

    private function apiThreadNode(string $uid, array $object, int $depth = 0): array
    {
        $node = $this->apiObject($uid, $object);
        $node['replies'] = [];

        if ($depth >= 8) {
            return $node;
        }

        $id = ActivityPub::objectId($object);
        if ($id === null) {
            return $node;
        }

        foreach ($this->repo->childrenOf($id) as $child) {
            if (!$this->apiCanReadObject($uid, $child)) {
                continue;
            }

            $node['replies'][] = $this->apiThreadNode($uid, $child, $depth + 1);
        }

        usort($node['replies'], static fn (array $a, array $b): int => strcmp((string)($a['published'] ?? ''), (string)($b['published'] ?? '')));
        return $node;
    }

    private function apiObject(string $uid, array $object): array
    {
        $actorId = ActivityPub::attributedTo($object) ?? '';
        $id = ActivityPub::objectId($object) ?? '';
        $interactions = new InteractionService(
            $this->store,
            $this->users,
            new FileQueue($this->store),
            new SocialGraph($this->store),
            new ActorRepository($this->store),
            $this->config,
        );

        return [
            'id' => $id,
            'type' => ActivityPub::objectType($object),
            'url' => is_string($object['url'] ?? null) ? $object['url'] : $id,
            'actor' => $actorId !== '' ? $this->renderer->actorInfo($actorId) + ['id' => $actorId] : null,
            'published' => ActivityPub::published($object),
            'updated' => is_string($object['updated'] ?? null) ? $object['updated'] : null,
            'visibility' => $this->apiObjectVisibility($object),
            'inReplyTo' => ActivityPub::inReplyTo($object),
            'content' => is_string($object['content'] ?? null) ? $object['content'] : '',
            'sourceContent' => is_string($object['sourceContent'] ?? null) ? $object['sourceContent'] : null,
            'summary' => is_string($object['summary'] ?? null) ? $object['summary'] : '',
            'attachments' => is_array($object['attachment'] ?? null) ? $object['attachment'] : [],
            'tags' => is_array($object['tag'] ?? null) ? $object['tag'] : [],
            'counts' => $interactions->counts($object),
            'canEdit' => $this->apiCanEditObject($uid, $object),
        ];
    }

    private function apiObjectVisibility(array $object): string
    {
        if (ActivityPub::isPublicObject($object)) {
            return 'public';
        }

        foreach (ActivityPub::audience($object) as $target) {
            if (is_string($target) && str_ends_with($target, '/followers')) {
                return 'followers';
            }
        }

        return 'direct';
    }

    private function apiCanEditObject(string $uid, array $object): bool
    {
        $actor = ActivityPub::attributedTo($object);
        if ($actor === null) {
            return false;
        }

        return in_array($actor, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true);
    }

    private function apiUser(string $uid): array
    {
        return [
            'uid' => $uid,
            'actor' => $this->renderer->localUserInfo($uid) + ['id' => $this->users->actorId($uid)],
        ];
    }

    private function apiActor(string $actorId): array
    {
        return $this->renderer->actorInfo($actorId) + ['id' => $actorId];
    }

    private function tagPage(string $tag): void
    {
        $tag = $this->normalizeTag($tag);
        if ($tag === '') {
            Http::notFound();
            return;
        }

        $limit = $this->timelinePageSize();
        $objects = $this->tagObjects($tag, 0, $limit + 1);
        $hasMore = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);
        $nextUrl = $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'tag', 'tag' => $tag, 'offset' => $limit]) : '';

        echo $this->renderer->tagPage($tag, $objects, $this->currentActions(), $nextUrl);
    }

    private function tagObjects(string $tag, int $offset, int $limit): array
    {
        $objects = [];
        $fetchOffset = $offset;
        $fetchLimit = $limit;

        while (count($objects) < $limit) {
            $batch = $this->repo->byTag($tag, $fetchLimit, $fetchOffset);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $object) {
                if (!is_array($object) || !ActivityPub::isPublicObject($object) || $this->objectBlocked($object)) {
                    continue;
                }

                $objects[] = $object;
                if (count($objects) >= $limit) {
                    break;
                }
            }

            if (count($batch) < $fetchLimit) {
                break;
            }

            $fetchOffset += $fetchLimit;
            $fetchLimit = $limit - count($objects);
            if ($fetchLimit <= 0) {
                break;
            }
        }

        return $objects;
    }

    private function objectHasTag(array $object, string $tag): bool
    {
        foreach ((array)($object['tag'] ?? []) as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = $entry['name'] ?? '';
            if (is_string($name) && $this->normalizeTag($name) === $tag) {
                return true;
            }
        }

        foreach (['sourceContent', 'content', 'summary', 'name'] as $field) {
            $value = $object[$field] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }

            preg_match_all('/(?<![\p{L}\p{N}_&])#([\p{L}\p{N}_][\p{L}\p{N}_-]{0,63})(?![\p{L}\p{N}_-])/u', strip_tags($value), $matches);
            foreach ($matches[1] ?? [] as $candidate) {
                if ($this->normalizeTag((string)$candidate) === $tag) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeTag(string $tag): string
    {
        return mb_strtolower(trim(ltrim($tag, "# \t\n\r\0\x0B")));
    }

    private function timelinePageSize(): int
    {
        return max(20, min(200, (int)($this->config['timeline_page_size'] ?? 80)));
    }

    private function publicUrl(array $query = []): string
    {
        $path = (string)($this->config['public_path'] ?? '');
        $path = $path === '' ? '/' : $path;

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function publicAssetUrl(string $asset): string
    {
        $path = (string)($this->config['public_path'] ?? '');
        $asset = ltrim($asset, '/');

        if ($path === '') {
            return '/' . $asset;
        }

        return rtrim(dirname($path), '/') . '/' . $asset;
    }

    private function privateTimeline(string $uid, int $limit = 80, int $offset = 0): array
    {
        $this->refreshTimelineInbox();

        $graph = new SocialGraph($this->store);
        $relations = new SocialRelationService($this->store);
        $actorIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
        $followedActorIds = [];

        foreach ($graph->following($uid) as $actor) {
            $actorId = ActivityPub::objectId($actor);

            if ($actorId !== null) {
                if ($relations->isMuted($uid, $actorId) || $relations->isBlocked($uid, $actorId)) {
                    continue;
                }

                $actorIds[] = $actorId;
                $followedActorIds[] = $actorId;
                $localFollowedUid = $this->localUidForActorId($actorId);
                if ($localFollowedUid !== null) {
                    $actorIds[] = $this->users->actorId($localFollowedUid);
                    $actorIds = array_merge($actorIds, $this->users->legacyActorIds($localFollowedUid));
                }
            }
        }

        $interactions = new InteractionService(
            $this->store,
            $this->users,
            new FileQueue($this->store),
            $graph,
            new ActorRepository($this->store),
            $this->config,
        );
        $fetchLimit = max($limit, $offset + $limit);
        $objects = array_merge(
            $this->repo->byAnyActor(array_values(array_unique($actorIds)), $fetchLimit),
            $interactions->remoteBoostedObjectsForUser($uid, $followedActorIds, $fetchLimit),
            $this->notificationTimelineObjects($uid, $fetchLimit)
        );
        $objects = array_values(array_filter(
            $objects,
            fn (array $object): bool => $this->objectVisibleInUserTimeline($uid, $object, $graph)
        ));
        $objects = $this->uniqueTimelineObjects($objects);

        usort($objects, fn (array $a, array $b): int => strcmp(
            $this->timelineSortDate($b),
            $this->timelineSortDate($a)
        ));

        return array_slice($objects, $offset, $limit);
    }

    private function notificationTimelineObjects(string $uid, int $limit): array
    {
        $items = [];

        foreach (glob($this->store->dataDir() . '/users/' . rawurlencode($uid) . '/notify/*.json') ?: [] as $file) {
            try {
                $record = Json::decodeFile($file);
            } catch (\Throwable) {
                continue;
            }

            $type = (string)($record['type'] ?? '');
            if (!in_array($type, ['Create', 'Mention', 'Webmention'], true)) {
                continue;
            }

            $objid = (string)($record['objid'] ?? '');
            if ($objid === '') {
                continue;
            }

            $object = $this->repo->findByIdOrAlias($objid);
            if ($object === null) {
                continue;
            }

            $date = (string)($record['date'] ?? '');
            if ($date !== '') {
                $object['_oannes_notified_at'] = $date;
            }

            $items[] = $object;
        }

        usort($items, fn (array $a, array $b): int => strcmp(
            $this->timelineSortDate($b),
            $this->timelineSortDate($a)
        ));

        return array_slice($items, 0, $limit);
    }

    private function uniqueTimelineObjects(array $objects): array
    {
        $unique = [];

        foreach ($objects as $object) {
            $id = ActivityPub::objectId($object);
            if ($id === null) {
                continue;
            }

            $existing = $unique[$id] ?? null;
            if (!is_array($existing) || $this->timelineSortDate($object) > $this->timelineSortDate($existing)) {
                $unique[$id] = $object;
            }
        }

        return array_values($unique);
    }

    private function timelineSortDate(array $object): string
    {
        foreach (['_oannes_boosted_at', '_oannes_notified_at', '_oannes_thread_activity_at'] as $key) {
            $value = $object[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return ActivityPub::published($object);
    }

    private function refreshTimelineInbox(): void
    {
        if ($this->timelineInboxRefreshed || !(bool)($this->config['inbox_enabled'] ?? false)) {
            return;
        }

        if (((new InstanceSettings($this->store, $this->config))->all()['update_mode'] ?? 'activity') === 'cron') {
            return;
        }

        $this->timelineInboxRefreshed = true;

        try {
            $limit = max(1, min(50, (int)($this->config['timeline_refresh_inbox_limit'] ?? 10)));
            (new InboxWorker($this->store, new FileQueue($this->store), $this->config))->run($limit);
        } catch (\Throwable) {
            // Timeline rendering must not fail because one remote activity is malformed.
        }
    }

    private function runOpportunisticMaintenance(string $route, string $method): void
    {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        if ($route === '.well-known/webfinger' || $route === '.well-known/nodeinfo' || str_starts_with($route, 'nodeinfo/')) {
            return;
        }

        if (preg_match('#^u/[^/]+/(?:inbox|outbox|followers|following)$#', $route) === 1) {
            return;
        }

        try {
            (new OpportunisticMaintenance($this->store, $this->config))->run('web');
        } catch (\Throwable) {
            // Public pages and timelines must keep rendering even if maintenance fails.
        }
    }

    private function objectVisibleInUserTimeline(string $uid, array $object, ?SocialGraph $graph = null): bool
    {
        if ($this->objectBlocked($object)) {
            return false;
        }

        $actor = ActivityPub::attributedTo($object);
        if ($actor !== null && $this->isLocalActorId($actor)) {
            return true;
        }

        $graph ??= new SocialGraph($this->store);
        if ($actor !== null && $graph->isFollowing($uid, $actor)) {
            return true;
        }

        $boostedBy = $object['_oannes_boosted_by'] ?? null;
        if (is_string($boostedBy) && $boostedBy !== '' && $graph->isFollowing($uid, $boostedBy)) {
            return true;
        }

        if (ActivityPub::isPublicObject($object) && $this->objectBelongsToFollowedThread($uid, $object, $graph)) {
            return true;
        }

        if (ActivityPub::isPublicObject($object)) {
            return false;
        }

        $audience = ActivityPub::audience($object);
        $localIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));

        foreach ($localIds as $actorId) {
            if (in_array($actorId, $audience, true)) {
                return true;
            }
        }

        $receivedBy = $object['_oannes_inbox_uids'] ?? [];
        return is_array($receivedBy) && in_array($uid, $receivedBy, true);
    }

    private function objectBelongsToFollowedThread(string $uid, array $object, SocialGraph $graph): bool
    {
        $seen = [];
        $current = $object;

        for ($depth = 0; $depth < 8; $depth++) {
            $parentId = ActivityPub::inReplyTo($current);
            if ($parentId === null || isset($seen[$parentId])) {
                return false;
            }

            $seen[$parentId] = true;
            $parent = $this->repo->findByIdOrAlias($parentId);
            if ($parent === null || $this->objectBlocked($parent)) {
                return false;
            }

            $parentActor = ActivityPub::attributedTo($parent);
            if ($parentActor !== null && ($this->isLocalActorId($parentActor) || $graph->isFollowing($uid, $parentActor))) {
                return true;
            }

            $current = $parent;
        }

        return false;
    }

    private function objectBlocked(array $object): bool
    {
        $actor = ActivityPub::attributedTo($object);
        return $actor !== null && $this->actorBlocked($actor);
    }

    private function actorBlocked(string $actorId): bool
    {
        return (new InstanceSettings($this->store, $this->config))->isActorBlocked($actorId);
    }

    private function latestNotifications(string $uid, int $limit = 12): array
    {
        $relations = new SocialRelationService($this->store);
        $records = [];

        foreach (glob($this->store->dataDir() . '/users/' . rawurlencode($uid) . '/notify/*.json') ?: [] as $file) {
            try {
                $record = Json::decodeFile($file);
            } catch (\Throwable) {
                continue;
            }

            $records[] = $record;
        }

        usort($records, static fn (array $a, array $b): int => strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? '')));

        $items = [];
        foreach ($records as $record) {
            $actor = (string)($record['actor'] ?? '');
            if ($actor !== '' && $relations->isBlocked($uid, $actor)) {
                continue;
            }

            $type = (string)($record['type'] ?? 'Notificación');
            $objid = (string)($record['objid'] ?? '');
            $reason = is_string($record['reason'] ?? null) ? (string)$record['reason'] : null;

            if (in_array($type, ['Like', 'Announce'], true)) {
                $reason = $this->notificationInteractionReason($uid, $type, $objid);
                if ($reason === null) {
                    continue;
                }
            }

            if ($type === 'Webmention') {
                $object = $objid !== '' ? $this->repo->findByIdOrAlias($objid) : null;
                if ($object === null || !$this->apiCanEditObject($uid, $object)) {
                    continue;
                }

                $reason = 'own_post';
            }

            if ($type === 'Create') {
                $object = $objid !== '' ? $this->repo->findByIdOrAlias($objid) : null;
                if ($object === null || !$this->localUserParticipatedInThread($uid, $object)) {
                    continue;
                }
            }

            $items[] = [
                'type' => $type,
                'label' => match ($type) {
                    'Like' => 'Favorito',
                    'Announce' => 'Impulso',
                    'Follow' => 'Nuevo seguidor',
                    'Create' => 'Respuesta',
                    'Mention' => 'Mención',
                    'Webmention' => 'Webmention',
                    default => $type,
                },
                'actor' => $actor,
                'objid' => $objid,
                'date' => (string)($record['date'] ?? ''),
                'reason' => $reason,
            ];

            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function notificationInteractionReason(string $uid, string $type, string $objectId): ?string
    {
        if ($objectId === '') {
            return null;
        }

        $cacheKey = $uid . "\n" . $type . "\n" . $objectId;
        if (array_key_exists($cacheKey, $this->notificationInteractionReasonCache)) {
            return $this->notificationInteractionReasonCache[$cacheKey];
        }

        if ($this->objectIdLooksOwnedByUser($uid, $objectId)) {
            return $this->notificationInteractionReasonCache[$cacheKey] = 'own_post';
        }

        if ($type === 'Like') {
            return $this->notificationInteractionReasonCache[$cacheKey] = null;
        }

        if ($type === 'Announce' && $this->apiInteractionService()->hasLocalReactionForCanonicalId($uid, $objectId, 'Announce')) {
            return $this->notificationInteractionReasonCache[$cacheKey] = 'shared_boost';
        }

        return $this->notificationInteractionReasonCache[$cacheKey] = null;
    }

    private function objectIdLooksOwnedByUser(string $uid, string $objectId): bool
    {
        foreach (array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)) as $actorId) {
            if (str_starts_with($objectId, rtrim($actorId, '/') . '/')) {
                return true;
            }
        }

        return false;
    }

    private function localUserParticipatedInThread(string $uid, array $object): bool
    {
        $objectId = ActivityPub::objectId($object) ?? Id::digest(Json::encode($object));
        $cacheKey = $uid . "\n" . $objectId;
        if (array_key_exists($cacheKey, $this->threadParticipationCache)) {
            return $this->threadParticipationCache[$cacheKey];
        }

        if ($this->apiCanEditObject($uid, $object)) {
            return $this->threadParticipationCache[$cacheKey] = true;
        }

        $root = $this->threadRoot($this->repo, $object);
        if ($this->apiCanEditObject($uid, $root)) {
            return $this->threadParticipationCache[$cacheKey] = true;
        }

        $rootId = ActivityPub::objectId($root);
        if ($rootId === null) {
            return $this->threadParticipationCache[$cacheKey] = false;
        }

        return $this->threadParticipationCache[$cacheKey] = $this->threadContainsLocalUserObject($uid, $rootId);
    }

    private function threadRoot(ObjectRepository $repo, array $object): array
    {
        $root = $object;
        $seen = [];

        for ($depth = 0; $depth < 8; $depth++) {
            $parentId = ActivityPub::inReplyTo($root);
            if ($parentId === null || isset($seen[$parentId])) {
                break;
            }

            $seen[$parentId] = true;
            $parent = $repo->findByIdOrAlias($parentId);
            if ($parent === null) {
                break;
            }

            $root = $parent;
        }

        return $root;
    }

    private function threadContainsLocalUserObject(string $uid, string $parentId, int $depth = 0): bool
    {
        if ($depth >= 8) {
            return false;
        }

        foreach ($this->repo->childrenOf($parentId) as $child) {
            if ($this->apiCanEditObject($uid, $child)) {
                return true;
            }

            $childId = ActivityPub::objectId($child);
            if ($childId !== null && $this->threadContainsLocalUserObject($uid, $childId, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private function latestPrivateMessages(string $uid, int $limit = 30): array
    {
        $idx = $this->store->dataDir() . '/users/' . rawurlencode($uid) . '/private.idx';
        $relations = new SocialRelationService($this->store);
        $messages = [];
        $hashes = is_file($idx) ? (file($idx, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

        foreach ($hashes as $hash) {
            $hash = trim((string)$hash);
            if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
                continue;
            }

            $file = $this->store->dataDir() . '/users/' . rawurlencode($uid) . '/private/' . $hash . '.json';
            if (!is_file($file)) {
                continue;
            }

            try {
                $object = Json::decodeFile($file);
            } catch (\Throwable) {
                continue;
            }

            if (!$this->isReceivedPrivateMessage($uid, $object)) {
                continue;
            }

            $actor = ActivityPub::attributedTo($object) ?? '';
            if ($actor !== '' && $relations->isBlocked($uid, $actor)) {
                continue;
            }

            $message = $this->privateMessageFromObject($object);
            if ($message !== null) {
                $messages[$message['id'] !== '' ? $message['id'] : 'received:' . $hash] = $message;
            }
        }

        foreach ($this->sentPrivateMessages($uid, $limit * 3) as $object) {
            $message = $this->privateMessageFromObject($object);
            if ($message !== null) {
                $messages[$message['id'] !== '' ? $message['id'] : 'sent:' . count($messages)] = $message;
            }
        }

        $messages = array_values($messages);
        usort($messages, static fn (array $a, array $b): int => strcmp(
            (string)($b['published'] ?? ''),
            (string)($a['published'] ?? '')
        ));

        return array_slice($messages, 0, $limit);
    }

    private function sentPrivateMessages(string $uid, int $limit): array
    {
        $localActorIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
        $objects = [];

        foreach ($this->repo->byAnyActor($localActorIds, $limit) as $object) {
            if ($this->isSentPrivateMessage($uid, $object)) {
                $objects[] = $object;
            }
        }

        return $objects;
    }

    private function isReceivedPrivateMessage(string $uid, array $object): bool
    {
        $audience = $this->audienceValues($object);

        return !in_array('https://www.w3.org/ns/activitystreams#Public', $audience, true)
            && array_intersect(array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), $audience) !== [];
    }

    private function isSentPrivateMessage(string $uid, array $object): bool
    {
        $audience = $this->audienceValues($object);
        $followers = $this->users->actorId($uid) . '/followers';

        return !in_array('https://www.w3.org/ns/activitystreams#Public', $audience, true)
            && !in_array($followers, $audience, true)
            && $audience !== [];
    }

    private function audienceValues(array $object): array
    {
        $values = [];

        foreach (['to', 'cc', 'bto', 'bcc'] as $field) {
            $items = $object[$field] ?? [];
            $items = is_array($items) ? $items : [$items];

            foreach ($items as $item) {
                if (is_string($item) && $item !== '') {
                    $values[] = $item;
                } elseif (is_array($item)) {
                    $id = ActivityPub::objectId($item);
                    if ($id !== null) {
                        $values[] = $id;
                    }
                }
            }
        }

        return array_values(array_unique($values));
    }

    private function recentPostDuplicate(string $uid, string $content, string $visibility, string $inReplyTo, string $imageAlt, string $fileField): ?string
    {
        $signature = $this->postSubmissionSignature($uid, $content, $visibility, $inReplyTo, $imageAlt, $fileField);
        $recent = $this->recentPostSubmissions();
        $record = $recent[$signature] ?? null;

        if (!is_array($record)) {
            return null;
        }

        $created = (int)($record['created'] ?? 0);
        $id = $record['id'] ?? null;

        return is_string($id) && $id !== '' && $created >= time() - 120 ? $id : null;
    }

    private function rememberRecentPost(string $uid, string $content, string $visibility, string $inReplyTo, string $imageAlt, string $fileField, string $id): void
    {
        if ($id === '') {
            return;
        }

        $signature = $this->postSubmissionSignature($uid, $content, $visibility, $inReplyTo, $imageAlt, $fileField);
        $recent = $this->recentPostSubmissions();
        $now = time();
        $recent[$signature] = [
            'id' => $id,
            'created' => $now,
        ];

        foreach ($recent as $key => $record) {
            if (!is_array($record) || (int)($record['created'] ?? 0) < $now - 300) {
                unset($recent[$key]);
            }
        }

        $_SESSION['recent_post_submissions'] = $recent;
    }

    /**
     * @return array<string, array{id?: string, created?: int}>
     */
    private function recentPostSubmissions(): array
    {
        $recent = $_SESSION['recent_post_submissions'] ?? [];
        return is_array($recent) ? $recent : [];
    }

    private function postSubmissionSignature(string $uid, string $content, string $visibility, string $inReplyTo, string $imageAlt, string $fileField): string
    {
        return Id::digest(Json::encode([
            'uid' => $uid,
            'content' => trim($content),
            'visibility' => $visibility,
            'inReplyTo' => trim($inReplyTo),
            'imageAlt' => trim($imageAlt),
            'upload' => $this->uploadedFileSignature($fileField),
        ]));
    }

    private function uploadedFileSignature(string $field): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file)) {
            return '';
        }

        $files = [];
        if (is_array($file['error'] ?? null)) {
            $count = count($file['error']);
            for ($i = 0; $i < $count; $i++) {
                if ((int)($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }

                $files[] = [
                    'name' => is_string($file['name'][$i] ?? null) ? $file['name'][$i] : '',
                    'size' => (int)($file['size'][$i] ?? 0),
                    'tmp' => is_string($file['tmp_name'][$i] ?? null) ? $file['tmp_name'][$i] : '',
                ];
            }
        } elseif ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $files[] = [
                'name' => is_string($file['name'] ?? null) ? $file['name'] : '',
                'size' => (int)($file['size'] ?? 0),
                'tmp' => is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '',
            ];
        }

        $signatures = [];
        foreach ($files as $item) {
            $tmp = $item['tmp'];
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                continue;
            }

            $hash = hash_file('sha256', $tmp);
            $signatures[] = [
                'name' => $item['name'],
                'size' => $item['size'],
                'hash' => is_string($hash) ? $hash : '',
            ];
        }

        return Json::encode($signatures);
    }

    private function attachmentAlts(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        return array_map(static fn (string $line): string => trim($line), $lines);
    }

    private function mergeEditedAttachments(string $id, array $existingInput, array $newAttachments, array $alts): array
    {
        $object = $this->repo->findByIdOrAlias($id);
        $existingOriginal = is_array($object) ? $this->imageAttachmentsForEdit($object) : [];
        $merged = [];
        $max = max(1, (int)($this->config['max_attachment_count'] ?? 4));

        for ($i = 0; $i < $max; $i++) {
            if (is_array($newAttachments[$i] ?? null)) {
                $merged[] = $newAttachments[$i];
                continue;
            }

            $candidate = $this->decodeExistingAttachment((string)($existingInput[$i] ?? ''));
            if ($candidate === null || !isset($existingOriginal[$i]) || !$this->sameAttachment($candidate, $existingOriginal[$i])) {
                continue;
            }

            $alt = trim((string)($alts[$i] ?? ''));
            if ($alt !== '') {
                $candidate['name'] = $alt;
                $candidate['summary'] = $alt;
            } else {
                unset($candidate['name'], $candidate['summary']);
            }

            $merged[] = $candidate;
        }

        return $merged;
    }

    private function decodeExistingAttachment(string $encoded): ?array
    {
        if ($encoded === '') {
            return null;
        }

        $json = base64_decode($encoded, true);
        if (!is_string($json) || $json === '') {
            return null;
        }

        $attachment = json_decode($json, true);
        return is_array($attachment) ? $attachment : null;
    }

    private function imageAttachmentsForEdit(array $object): array
    {
        $attachments = $object['attachment'] ?? [];
        $attachments = is_array($attachments) && array_is_list($attachments) ? $attachments : [$attachments];
        $images = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $mediaType = is_string($attachment['mediaType'] ?? null) ? strtolower($attachment['mediaType']) : '';
            if (!str_starts_with($mediaType, 'image/')) {
                continue;
            }

            $url = $attachment['url'] ?? $attachment['href'] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }

            $images[] = $attachment;
        }

        return array_slice($images, 0, max(1, (int)($this->config['max_attachment_count'] ?? 4)));
    }

    private function sameAttachment(array $a, array $b): bool
    {
        foreach (['url', 'href', 'mediaType', 'type'] as $field) {
            if (($a[$field] ?? null) !== ($b[$field] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function privateMessageFromObject(array $object): ?array
    {
        $content = is_string($object['content'] ?? null) ? $object['content'] : '';
        $id = ActivityPub::objectId($object) ?? '';

        if ($id === '' && $content === '') {
            return null;
        }

        return [
            'id' => $id,
            'actor' => ActivityPub::attributedTo($object) ?? '',
            'inReplyTo' => ActivityPub::inReplyTo($object) ?? '',
            'published' => ActivityPub::published($object),
            'content' => $content,
        ];
    }
}
