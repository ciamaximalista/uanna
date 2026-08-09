<?php

namespace Oannes;

final class Router
{
    private bool $timelineInboxRefreshed = false;

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
            $this->actor($match[1]);
            return;
        }

        if (preg_match('#^legacy-user/([^/]+)$#', $route, $match)) {
            $this->actor($match[1]);
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

        if ($route === 'admin/react') {
            $this->adminReact($method);
            return;
        }

        if ($route === 'admin/private-message') {
            $this->adminPrivateMessage($method);
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

        if ($path === '.well-known/webfinger' || $path === '.well-known/nodeinfo' || $path === 'nodeinfo/2.1') {
            return $path;
        }

        if (preg_match('#^u/[a-zA-Z0-9_-]+(?:/(?:outbox|inbox|followers|following))?$#', $path)) {
            return $path;
        }

        if (preg_match('#^([a-zA-Z0-9_-]{1,64})/(outbox|inbox|followers|following)$#', $path, $match) && $this->users->find($match[1]) !== null) {
            return 'legacy-user/' . $match[1] . '/' . $match[2];
        }

        if (preg_match('/^@([a-zA-Z0-9_-]{1,64})$/', $path, $match) && $this->users->find($match[1]) !== null) {
            return 'legacy-user/' . $match[1];
        }

        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $path) && $this->users->find($path) !== null) {
            return 'legacy-user/' . $path;
        }

        $id = rtrim((string)$this->config['base_url'], '/') . '/' . $path;

        if ($this->repo->findByIdOrAlias($id) !== null) {
            $_GET['id'] = $id;
        }

        return '';
    }

    private function html(): void
    {
        $id = $_GET['id'] ?? null;
        $uid = $_GET['user'] ?? null;

        if (is_string($id) && $id !== '') {
            $format = $_GET['format'] ?? '';
            if ($format === 'xml') {
                $object = $this->repo->findByIdOrAlias($id);
                if ($object === null || !ActivityPub::isPublicObject($object)) {
                    Http::notFound();
                    return;
                }

                header('Content-Type: application/xml; charset=utf-8');
                echo (new XmlExporter())->objectToXml($object);
                return;
            }

            if (Http::wantsActivityJson()) {
                $object = $this->repo->findByIdOrAlias($id);
                if ($object === null || !ActivityPub::isPublicObject($object)) {
                    Http::notFound();
                    return;
                }

                Http::activityJson($object);
                return;
            }

            echo $this->renderer->objectPage($id);
            return;
        }

        if (is_string($uid) && $uid !== '') {
            echo $this->renderer->userPage($uid);
            return;
        }

        $auth = $this->auth ?? new Auth($this->store);
        $currentUid = $auth->currentUser();

        if ($currentUid !== null) {
            echo $this->renderer->privateTimelinePage($currentUid, $this->privateTimeline($currentUid), $auth->csrfToken());
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
            'aliases' => [
                $this->users->actorId($uid),
                $this->users->webUrl($uid),
            ],
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

    private function actor(string $uid): void
    {
        $user = $this->users->find($uid);

        if ($user === null) {
            Http::notFound();
            return;
        }

        if (Http::wantsActivityJson()) {
            Http::activityJson($this->users->activityPubActor($uid, $user));
            return;
        }

        echo $this->renderer->userPage($uid);
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

        Http::activityJson([
            '@context' => 'https://www.w3.org/ns/activitystreams',
            'id' => $actorId . '/outbox',
            'type' => 'OrderedCollection',
            'totalItems' => count($objects),
            'orderedItems' => $objects,
        ]);
    }

    private function publicObjects(array $objects): array
    {
        return array_values(array_filter($objects, static fn (array $object): bool => ActivityPub::isPublicObject($object)));
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

    private function countLocalPosts(): int
    {
        $total = 0;

        foreach ($this->users->all() as $uid => $_user) {
            if (is_string($uid)) {
                $total += count($this->repo->byAnyActor(
                    array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)),
                    100000
                ));
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

        echo (new AdminRenderer($this->renderer, $auth))->instanceAdmin(
            $uid,
            $this->users->all(),
            $settings->all(),
            $settings->blockedServers(),
            $settings->blockNotices(),
            $message,
            $error,
            $openBox
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

        $actor = is_string($_POST['actor'] ?? null) ? $_POST['actor'] : '';
        (new InstanceSettings($this->store, $this->config))->addBlockedActor($actor);
        $this->instanceAdmin('Actor bloqueado en todo el servidor.');
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

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($content)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $attachment = (new MediaUploadService($this->config))->saveImageFromPost(
                $uid,
                'image_upload',
                is_string($imageAlt) ? $imageAlt : ''
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
                'attachments' => $attachment !== null ? [$attachment] : [],
            ]);
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

        if (!$auth->checkCsrf(is_string($csrf) ? $csrf : null) || !is_string($id) || !is_string($content)) {
            echo $this->adminDashboard($uid, $auth, null, 'Solicitud no válida.');
            return;
        }

        try {
            $attachment = (new MediaUploadService($this->config))->saveImageFromPost(
                $uid,
                'image_upload',
                is_string($imageAlt) ? $imageAlt : ''
            );
            $note = (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->updateNote($uid, $id, $content, [
                'attachments' => $attachment !== null ? [$attachment] : [],
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
            (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->deleteNote($uid, $id);
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
                'lang' => $_POST['lang'] ?? '',
                'tz' => $_POST['tz'] ?? '',
                'approve_followers' => isset($_POST['approve_followers']),
            ];

            if ($avatar !== null) {
                $fields['avatar'] = $avatar;
            }

            if ($header !== null) {
                $fields['header'] = $header;
            }

            $this->users->updateProfile($uid, [
                ...$fields,
            ]);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        echo $this->adminDashboard($uid, $auth, 'Perfil guardado.');
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

        header('Location: ' . $this->homeLocation());
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
            (new PostService(
                $this->store,
                $this->users,
                new FileQueue($this->store),
                new SocialGraph($this->store),
                $this->config,
            ))->createNote($uid, $content, [
                'visibility' => 'direct',
                'to' => $to,
            ]);
        } catch (\Throwable $e) {
            echo $this->adminDashboard($uid, $auth, null, $e->getMessage());
            return;
        }

        echo $this->adminDashboard($uid, $auth, 'Mensaje privado enviado.');
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

    private function followActor(string $uid, string $actorId, SocialGraph $graph): string
    {
        if ($graph->isFollowing($uid, $actorId)) {
            return 'Ya sigues a ese usuario.';
        }

        $actor = $this->actorForSocialAction($uid, $actorId);
        $graph->addFollowing($uid, $actor);

        $inbox = $graph->inboxForActor($actor);
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
        }

        return 'Usuario añadido a seguidos.';
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
        [$pendingFollows, $pendingCreates] = $this->pendingModeration($uid);
        $timelineSearchQuery = $_GET['timeline_q'] ?? '';
        $timelineSearchQuery = is_string($timelineSearchQuery) ? trim($timelineSearchQuery) : '';
        $timeline = $this->privateTimeline($uid);
        $timelineSearchResults = $timelineSearchQuery !== ''
            ? $this->renderer->objectList($this->searchTimeline($timeline, $timelineSearchQuery), false, [
                'actions' => [
                    'uid' => $uid,
                    'csrf' => $auth->csrfToken(),
                ],
            ])
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
        );
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

    private function privateTimeline(string $uid): array
    {
        $this->refreshTimelineInbox();

        $graph = new SocialGraph($this->store);
        $relations = new SocialRelationService($this->store);
        $actorIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));

        foreach ($graph->following($uid) as $actor) {
            $actorId = ActivityPub::objectId($actor);

            if ($actorId !== null) {
                if ($relations->isMuted($uid, $actorId) || $relations->isBlocked($uid, $actorId)) {
                    continue;
                }

                $actorIds[] = $actorId;
                $localFollowedUid = $this->localUidForActorId($actorId);
                if ($localFollowedUid !== null) {
                    $actorIds[] = $this->users->actorId($localFollowedUid);
                    $actorIds = array_merge($actorIds, $this->users->legacyActorIds($localFollowedUid));
                }
            }
        }

        return array_values(array_filter(
            $this->repo->byAnyActor(array_values(array_unique($actorIds)), 80),
            fn (array $object): bool => $this->objectVisibleInUserTimeline($uid, $object)
        ));
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
            (new InboxWorker($this->store, new FileQueue($this->store)))->run($limit);
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

    private function objectVisibleInUserTimeline(string $uid, array $object): bool
    {
        if (ActivityPub::isPublicObject($object)) {
            return true;
        }

        $receivedBy = $object['_oannes_inbox_uids'] ?? [];
        if (is_array($receivedBy) && in_array($uid, $receivedBy, true)) {
            return true;
        }

        $audience = ActivityPub::audience($object);
        $localIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));

        foreach ($localIds as $actorId) {
            if (in_array($actorId, $audience, true)) {
                return true;
            }
        }

        return ActivityPub::attributedTo($object) === $this->users->actorId($uid);
    }

    private function latestNotifications(string $uid, int $limit = 12): array
    {
        $root = dirname($this->store->dataDir(), 2);
        $relations = new SocialRelationService($this->store);
        $items = [];

        foreach (glob($root . '/user/' . $uid . '/notify/*.json') ?: [] as $file) {
            try {
                $record = Json::decodeFile($file);
            } catch (\Throwable) {
                continue;
            }

            $actor = (string)($record['actor'] ?? '');
            if ($actor !== '' && $relations->isBlocked($uid, $actor)) {
                continue;
            }

            $type = (string)($record['type'] ?? 'Notificación');
            $items[] = [
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
                'date' => (string)($record['date'] ?? ''),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp((string)$b['date'], (string)$a['date']));
        return array_slice($items, 0, $limit);
    }

    private function latestPrivateMessages(string $uid, int $limit = 30): array
    {
        $root = dirname($this->store->dataDir(), 2);
        $idx = $root . '/user/' . $uid . '/private.idx';
        $relations = new SocialRelationService($this->store);
        $messages = [];
        $hashes = is_file($idx) ? (file($idx, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];

        foreach ($hashes as $hash) {
            $hash = trim((string)$hash);
            if (!preg_match('/^[a-f0-9]{32}$/', $hash)) {
                continue;
            }

            $file = $root . '/user/' . $uid . '/private/' . $hash . '.json';
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
