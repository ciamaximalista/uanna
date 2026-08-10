<?php

namespace Oannes;

final class Renderer
{
    private readonly LocalUsers $users;
    private readonly ActorRepository $actors;
    private readonly InteractionService $interactions;
    private array $actorInfoCache = [];
    private array $canonicalIdCache = [];

    public function __construct(
        private readonly ObjectRepository $repo,
        private readonly array $config,
    ) {
        $store = new FileStore($this->config['data_dir']);
        $this->users = new LocalUsers($store, $this->config);
        $this->actors = new ActorRepository($store);
        $this->interactions = new InteractionService(
            $store,
            $this->users,
            new FileQueue($store),
            new SocialGraph($store),
            $this->actors,
            $this->config,
        );
    }

    public function page(string $title, string $body): string
    {
        $settings = new InstanceSettings(new FileStore($this->config['data_dir']), $this->config);
        $instanceName = $settings->instanceName();
        $name = Html::escape($instanceName);
        $pageTitle = trim($title) === ''
            ? $instanceName
            : $instanceName . ' | ' . trim($title);
        $pageTitle = Html::escape($pageTitle);

        $home = Html::escape($this->publicUrl());
        $style = Html::escape($this->assetUrl('style.css'));
        $cropScript = Html::escape($this->assetUrl('profile-crop.js'));
        $favicon = Html::escape($settings->faviconPath());
        $brand = $this->topbarBrand($home, $name);
        $composer = $this->composerControls();
        $panelLink = $this->panelNavLink();
        $adminLink = $this->adminNavLink();
        $footer = $this->siteFooter($name);

        return "<!doctype html>\n"
            . "<html lang=\"es\"><head><meta charset=\"utf-8\"/>"
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"/>"
            . "<title>{$pageTitle}</title>"
            . "<link rel=\"icon\" type=\"image/png\" href=\"{$favicon}\"/>"
            . "<link rel=\"apple-touch-icon\" href=\"{$favicon}\"/>"
            . "<link rel=\"stylesheet\" href=\"{$style}\"/>"
            . "<script defer src=\"{$cropScript}\"></script>"
            . "</head><body><header class=\"topbar\">{$brand}"
            . "<nav class=\"navlinks\"><a href=\"{$home}\">Inicio</a>{$panelLink}{$adminLink}{$composer['button']}</nav></header>"
            . "<main>{$body}</main>{$footer}{$composer['modal']}</body></html>";
    }

    private function panelNavLink(): string
    {
        $href = Html::escape($this->publicUrl(['route' => 'admin']));
        $auth = new Auth(new FileStore($this->config['data_dir']));
        $uid = $auth->currentUser();

        if ($uid === null) {
            return '<a href="' . $href . '">Panel</a>';
        }

        $count = $this->panelAttentionCount($uid);
        if ($count <= 0) {
            return '<a href="' . $href . '">Panel</a>';
        }

        $target = Html::escape($this->publicUrl(['route' => 'admin', 'focus' => 'notifications']) . '#notifications');
        return '<a class="panel-link has-badge" href="' . $target . '">Panel <span class="nav-badge">' . Html::escape((string)min($count, 99)) . '</span></a>';
    }

    private function panelAttentionCount(string $uid): int
    {
        return $this->unreadNotificationCount($uid) + $this->pendingReviewCount($uid);
    }

    private function unreadNotificationCount(string $uid): int
    {
        $seen = $this->notificationsSeenAt($uid);
        $root = dirname((string)$this->config['data_dir'], 2);
        $count = 0;

        foreach (glob($root . '/user/' . $uid . '/notify/*.json') ?: [] as $file) {
            try {
                $record = Json::decodeFile($file);
            } catch (\Throwable) {
                continue;
            }

            $date = (string)($record['date'] ?? $record['created_at'] ?? '');
            if ($seen === '' || $date === '' || strcmp($date, $seen) > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function notificationsSeenAt(string $uid): string
    {
        $path = (string)$this->config['data_dir'] . '/state/users/' . rawurlencode($uid) . '/notifications.json';
        if (!is_file($path)) {
            return '';
        }

        try {
            $record = Json::decodeFile($path);
        } catch (\Throwable) {
            return '';
        }

        $seen = $record['seen_at'] ?? '';
        return is_string($seen) ? $seen : '';
    }

    private function pendingReviewCount(string $uid): int
    {
        $count = 0;
        $graph = new SocialGraph(new FileStore($this->config['data_dir']));
        foreach (['follows', 'creates'] as $kind) {
            foreach (glob((string)$this->config['data_dir'] . '/moderation/inbox/' . rawurlencode($uid) . '/' . $kind . '/*.json') ?: [] as $file) {
                $caseId = basename($file, '.json');
                if (is_file((string)$this->config['data_dir'] . '/state/moderation/inbox/' . rawurlencode($uid) . '/' . $kind . '/' . $caseId . '.json')) {
                    continue;
                }

                try {
                    $case = Json::decodeFile($file);
                } catch (\Throwable) {
                    continue;
                }

                if (($case['status'] ?? null) !== 'pending') {
                    continue;
                }

                if ($kind === 'follows' && $this->pendingFollowAlreadyFollower($uid, $case, $graph)) {
                    continue;
                }

                $count++;
            }
        }

        return $count;
    }

    private function pendingFollowAlreadyFollower(string $uid, array $case, SocialGraph $graph): bool
    {
        $record = $case['record'] ?? null;
        $activity = is_array($record) ? ($record['activity'] ?? null) : null;
        if (!is_array($activity)) {
            return false;
        }

        $actorId = ActivityPub::attributedTo($activity);
        return $actorId !== null && $graph->isFollower($uid, $actorId);
    }

    private function siteFooter(string $name): string
    {
        return '<footer class="site-footer"><p>' . $name
            . ' se hace con <a href="https://ruralnext.org/uanna" target="_blank" rel="noopener">Uanna</a>, '
            . 'software libre con licencia <a href="https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12" target="_blank" rel="noopener">EUPL 1.2<img class="site-footer-logo eupl-logo" src="' . Html::escape($this->assetUrl('EUPL-logo-04.png')) . '" alt=""/></a> '
            . 'desarrollado por <a href="https://maximalista.coop" target="_blank" rel="noopener">Compañía Maximalista S.Coop<img class="site-footer-logo" src="' . Html::escape($this->assetUrl('maximalista.png')) . '" alt=""/></a> '
            . 'para <a href="https://ruralnext.org" target="_blank" rel="noopener">RuralNEXT</a>.</p></footer>';
    }

    private function topbarBrand(string $home, string $fallbackName): string
    {
        $uid = (new Auth(new FileStore($this->config['data_dir'])))->currentUser();

        if ($uid === null) {
            $logo = Html::escape((string)($this->config['default_avatar_path'] ?? '/uanna.png'));
            $logo = Html::escape((new InstanceSettings(new FileStore($this->config['data_dir']), $this->config))->faviconPath());
            return '<a class="brand brand-logo" href="' . $home . '" aria-label="' . $fallbackName . '"><img src="' . $logo . '" alt=""/></a>';
        }

        $user = $this->users->find($uid) ?? [];
        $avatar = Html::escape($this->users->avatarUrl($user));
        $label = Html::escape((string)($user['name'] ?? $uid));
        $initial = Html::escape($this->initial($uid));
        $profile = Html::escape($this->users->webUrl($uid));
        $avatarHtml = '<img class="topbar-avatar" src="' . $avatar . '" alt=""/>';

        return '<a class="brand brand-user" href="' . $profile . '" aria-label="' . $label . '">' . $avatarHtml . '</a>';
    }

    private function adminNavLink(): string
    {
        $uid = (new Auth(new FileStore($this->config['data_dir'])))->currentUser();
        if ($uid === null) {
            return '';
        }

        $user = $this->users->find($uid);
        if (!is_array($user) || !((bool)($user['admin'] ?? false))) {
            return '';
        }

        return '<a href="' . Html::escape($this->publicUrl(['route' => 'instance-admin'])) . '">Admin</a>';
    }

    private function composerControls(): array
    {
        $auth = new Auth(new FileStore($this->config['data_dir']));
        $uid = $auth->currentUser();

        if ($uid === null) {
            return [
                'button' => '',
                'modal' => '',
            ];
        }

        $csrf = Html::escape($auth->csrfToken());

        return [
            'button' => '<a class="compose-trigger" href="#compose-modal" aria-label="Publicar">+</a>',
            'modal' => '<section id="compose-modal" class="modal-overlay" aria-label="Publicar">'
                . '<a class="modal-backdrop" href="#" aria-label="Cerrar"></a>'
                . '<article class="compose-modal">'
                . '<header><h2>Publicar</h2><a class="modal-close" href="#" aria-label="Cerrar">×</a></header>'
                . '<form method="post" action="?route=admin/post" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<label>Texto <textarea name="content" rows="7" required></textarea></label>'
                . '<label>Responder a <input name="inReplyTo" type="url" placeholder="https://..."/></label>'
                . '<label>Visibilidad <select name="visibility"><option value="public">Pública</option><option value="followers">Seguidores</option></select></label>'
                . '<label>Imagen <input name="image_upload" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
                . '<label>Texto alt <textarea name="image_alt" rows="3"></textarea></label>'
                . '<button type="submit">Publicar</button>'
                . '</form>'
                . '</article>'
                . '</section>',
        ];
    }

    public function home(): string
    {
        $settings = new InstanceSettings(new FileStore($this->config['data_dir']), $this->config);
        return $this->page('', '<section class="timeline">' . $this->instancePresentationCard($settings) . '</section>');
    }

    public function privateTimelinePage(string $uid, array $objects, string $csrf): string
    {
        $items = $objects !== []
            ? $this->objectList($objects, false, [
                'actions' => [
                    'uid' => $uid,
                    'csrf' => $csrf,
                ],
            ])
            : '<p class="muted">Todavía no hay publicaciones importadas de las cuentas que sigues.</p>';

        return $this->page('', '<section class="timeline">' . $items . '</section>');
    }

    public function userPage(string $uid, ?array $actions = null): string
    {
        $localUsers = new LocalUsers(new FileStore($this->config['data_dir']), $this->config);
        $user = $localUsers->find($uid);

        if ($user === null) {
            http_response_code(404);
            return $this->page('No encontrado', '<h1>No encontrado</h1>');
        }

        $items = '';
        $objects = array_merge(
            $this->repo->byAnyActor(array_merge([$localUsers->actorId($uid)], $localUsers->legacyActorIds($uid)), 240),
            $this->interactions->boostedObjectsByUser($uid, 80),
        );
        $objects = $this->sortObjectsForProfile($this->publicObjects($objects));

        $items = $this->profileTimeline($objects, $actions);

        $name = Html::escape((string)($user['name'] ?? $uid));
        $bio = Html::escape((string)($user['bio'] ?? ''));
        $host = Html::escape((string)$this->config['host']);
        $avatar = Html::escape($localUsers->avatarUrl($user));
        $header = Html::escape((string)($user['header'] ?? '') ?: $localUsers->defaultHeaderUrl());
        $avatarHtml = '<img class="avatar" src="' . $avatar . '" alt=""/>';
        $headerStyle = $header !== '' ? ' style="background-image:url(\'' . $header . '\')"' : '';

        $body = '<section class="profile hero-profile">'
            . '<div class="profile-cover"' . $headerStyle . '></div>'
            . '<div class="profile-main">' . $avatarHtml . '<div><h1>' . $name . '</h1>'
            . '<p class="meta">@' . Html::escape($uid) . '@' . $host . '</p></div></div>'
            . ($bio !== '' ? '<p class="bio">' . nl2br($bio) . '</p>' : '')
            . '</section><section class="timeline">' . $items . '</section>';

        return $this->page($name, $body);
    }

    public function actorPage(string $actorId, ?array $actions = null): string
    {
        $actorId = trim($actorId);
        $actor = $actorId !== '' ? $this->actors->findById($actorId) : null;

        if ($actorId === '' || $actor === null) {
            http_response_code(404);
            return $this->page('No encontrado', '<h1>No encontrado</h1>');
        }

        foreach ($this->users->all() as $uid => $_user) {
            if (!is_string($uid)) {
                continue;
            }

            if (in_array($actorId, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true)) {
                return $this->userPage($uid, $actions);
            }
        }

        $info = $this->actorInfo($actorId);
        $objects = $this->sortObjectsForProfile($this->publicObjects($this->repo->byAnyActor(ActivityPub::aliases($actor), 240)));
        $items = $this->profileTimeline($objects, $actions);
        $name = Html::escape((string)$info['label']);
        $handle = Html::escape($this->actorHandle($actor, $actorId));
        $avatar = Html::escape((string)$info['avatar']);
        $avatarHtml = $avatar !== ''
            ? '<img class="avatar" src="' . $avatar . '" alt=""/>'
            : '<span class="avatar avatar-fallback">' . Html::escape((string)$info['initial']) . '</span>';
        $externalUrl = Html::escape((string)$info['url']);

        $body = '<section class="profile hero-profile">'
            . '<div class="profile-cover"></div>'
            . '<div class="profile-main"><a href="' . $externalUrl . '">' . $avatarHtml . '</a><div><h1>' . $name . '</h1>'
            . ($handle !== '' ? '<p class="meta">' . $handle . '</p>' : '')
            . '<p class="meta"><a href="' . $externalUrl . '">Perfil original</a></p></div></div>'
            . '</section><section class="timeline">' . $items . '</section>';

        return $this->page((string)$info['label'], $body);
    }

    private function sortObjectsForProfile(array $objects): array
    {
        $unique = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $id = ActivityPub::objectId($object);
            if ($id !== null) {
                $unique[$id . ':' . (string)($object['_oannes_boosted_at'] ?? '')] = $object;
            }
        }

        $objects = array_values($unique);
        usort($objects, static fn (array $a, array $b): int => strcmp(
            (string)($b['_oannes_boosted_at'] ?? ActivityPub::published($b)),
            (string)($a['_oannes_boosted_at'] ?? ActivityPub::published($a))
        ));

        return array_slice($objects, 0, 80);
    }

    private function profileTimeline(array $objects, ?array $actions): string
    {
        $html = '';
        $renderedRoots = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $tree = $this->treeFor($this->profileThreadObjects($object));

            foreach ($tree as $node) {
                if (!is_array($node['object'] ?? null)) {
                    continue;
                }

                $rootId = ActivityPub::objectId($node['object']);
                if ($rootId !== null && isset($renderedRoots[$rootId])) {
                    continue;
                }

                if ($rootId !== null) {
                    $renderedRoots[$rootId] = true;
                }

                $html .= $this->objectCard($node['object'], false, [
                    'children' => $node['children'] ?? [],
                    'actions' => $actions,
                ]);
            }
        }

        return $html !== '' ? $html : '<p class="muted">Todavía no hay publicaciones públicas.</p>';
    }

    private function profileThreadObjects(array $object): array
    {
        $objectsById = [];
        $lineage = $this->publicLineage($object);
        $root = $lineage[0] ?? $object;

        foreach ($lineage as $lineageObject) {
            $this->addThreadObject($objectsById, $lineageObject);
        }

        $rootId = ActivityPub::objectId($root);
        if ($rootId !== null) {
            foreach ($this->replyDescendants($rootId) as $descendant) {
                $this->addThreadObject($objectsById, $descendant);
            }
        }

        $this->addThreadObject($objectsById, $object);

        return array_values($objectsById);
    }

    private function publicLineage(array $object): array
    {
        $lineage = [$object];
        $seen = [];
        $current = $object;

        for ($depth = 0; $depth < 8; $depth++) {
            $parent = ActivityPub::inReplyTo($current);
            if ($parent === null || isset($seen[$parent])) {
                break;
            }

            $seen[$parent] = true;
            $parentObject = $this->repo->findByIdOrAlias($parent);
            if ($parentObject === null || !ActivityPub::isPublicObject($parentObject)) {
                break;
            }

            array_unshift($lineage, $parentObject);
            $current = $parentObject;
        }

        return $lineage;
    }

    private function addThreadObject(array &$objectsById, array $object): void
    {
        $id = ActivityPub::objectId($object);
        if ($id !== null) {
            $objectsById[$this->canonicalObjectId($id)] = $object;
        }
    }

    public function objectPage(string $id, ?array $actions = null): string
    {
        $object = $this->repo->findByIdOrAlias($id);

        if ($object === null || !ActivityPub::isPublicObject($object)) {
            http_response_code(404);
            return $this->page('No encontrado', '<h1>No encontrado</h1>');
        }

        $title = $this->titleFor($object);
        $body = $this->objectCard($object, false, [
            'children' => $this->replyTree(ActivityPub::objectId($object) ?? $id),
            'actions' => $actions,
        ]);

        return $this->page($title, $body);
    }

    public function objectList(array $objects, bool $child = false, array $options = []): string
    {
        $html = '';
        $objects = $this->withMissingParents($objects);
        $tree = $this->treeFor($objects);

        foreach ($tree as $node) {
            if (is_array($node['object'] ?? null)) {
                $html .= $this->objectCard($node['object'], $child, [
                    'children' => $node['children'] ?? [],
                    'actions' => $options['actions'] ?? null,
                ]);
            }
        }

        return $html;
    }

    private function objectCard(array $object, bool $child, array $options = []): string
    {
        $id = ActivityPub::objectId($object) ?? '';
        $actor = ActivityPub::attributedTo($object) ?? '';
        $actorInfo = $this->actorInfo($actor);
        $published = ActivityPub::published($object);
        $publishedHuman = DateFormat::human($published, (string)($this->config['timezone'] ?? 'Europe/Madrid'));
        $content = $this->contentFor($object);
        $boostedAt = is_string($object['_oannes_boosted_at'] ?? null) ? $object['_oannes_boosted_at'] : '';
        $boostedHuman = DateFormat::human($boostedAt, (string)($this->config['timezone'] ?? 'Europe/Madrid'));
        $class = $child ? 'object child' : 'object';
        $anchor = $id !== '' ? $this->postAnchor($id) : '';
        $anchorAttr = $anchor !== '' ? ' id="' . Html::escape($anchor) . '"' : '';
        $url = $this->publicUrl(['id' => $id]);
        $interactionActors = $this->interactions->actors($object);
        $children = is_array($options['children'] ?? null) ? $options['children'] : [];
        $actions = is_array($options['actions'] ?? null) ? $options['actions'] : null;
        $avatar = Html::escape($actorInfo['avatar']);
        $actorProfileUrl = Html::escape((string)($actorInfo['url'] ?? '#'));
        $avatarInner = $avatar !== ''
            ? '<img class="post-avatar" src="' . $avatar . '" alt=""/>'
            : '<span class="post-avatar avatar-fallback">' . Html::escape($actorInfo['initial']) . '</span>';
        $avatarHtml = '<a class="post-avatar-link" href="' . $actorProfileUrl . '" aria-label="' . Html::escape((string)$actorInfo['label']) . '">' . $avatarInner . '</a>';
        $actorInternalUrl = Html::escape((string)($actorInfo['internal_url'] ?? $actorProfileUrl));
        $actorNameHtml = '<a class="post-author-link" href="' . $actorInternalUrl . '"><strong>' . Html::escape($actorInfo['label']) . '</strong></a>';
        $childrenHtml = $this->childrenHtml($children, $actions);
        $ownActions = $this->ownPostActions($object, $actions);
        $actionHtml = $this->actionBar($id, $interactionActors, $actions, $ownActions);
        $visibilityBadge = $this->visibilityBadge($object);

        $boostHtml = $boostedAt !== '' ? '<p class="boost-marker">Impulsado <time datetime="' . Html::escape($boostedAt) . '">' . Html::escape($boostedHuman) . '</time></p>' : '';

        return '<article class="' . $class . '"' . $anchorAttr . '>'
            . $boostHtml
            . '<header class="object-head">' . $avatarHtml . '<div>'
            . '<p class="meta post-meta">' . $actorNameHtml . '<br/>'
            . '<a href="' . Html::escape($url) . '"><time datetime="' . Html::escape($published) . '">' . Html::escape($publishedHuman) . '</time></a>' . $visibilityBadge . '</p></div></header>'
            . '<div class="content">' . $content . '</div>'
            . $actionHtml
            . $childrenHtml
            . '</article>';
    }

    private function visibilityBadge(array $object): string
    {
        if (ActivityPub::isPublicObject($object)) {
            return '';
        }

        foreach (ActivityPub::audience($object) as $target) {
            if (str_ends_with($target, '/followers')) {
                return '<span class="visibility-badge followers">Sólo para seguidores</span>';
            }
        }

        return '<span class="visibility-badge private">Privado</span>';
    }

    private function actionBar(string $id, array $interactionActors, ?array $actions, string $ownActions = ''): string
    {
        $stats = $this->interactionAvatars('Favoritos', $interactionActors['likes'] ?? [])
            . $this->interactionAvatars('Impulsos', $interactionActors['boosts'] ?? []);

        if ($actions === null || $id === '') {
            return '<footer class="post-actions post-stats">' . $stats . $ownActions . '</footer>';
        }

        $csrf = Html::escape((string)($actions['csrf'] ?? ''));
        $encodedId = Html::escape($id);
        $uid = (string)($actions['uid'] ?? '');
        $liked = $uid !== '' && $this->interactions->hasLocalReactionForCanonicalId($uid, $id, 'Like');
        $boosted = $uid !== '' && $this->interactions->hasLocalReactionForCanonicalId($uid, $id, 'Announce');
        $likeLabel = $liked ? 'Quitar fav' : 'Favoritear';
        $boostLabel = $boosted ? 'Quitar impulso' : 'Impulsar';
        $replyModal = $this->replyModal($id, $csrf);
        $returnTo = Html::escape($this->currentPageWithAnchor($this->postAnchor($id)));

        return '<footer class="post-actions">'
            . '<form method="post" action="?route=admin/react">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="id" value="' . $encodedId . '"/>'
            . '<input type="hidden" name="return_to" value="' . $returnTo . '"/>'
            . '<button type="submit" name="type" value="Like">' . $likeLabel . '</button>'
            . '<button type="submit" name="type" value="Announce">' . $boostLabel . '</button>'
            . '</form>'
            . '<a class="button-link reply-link" href="#reply-' . Html::escape(substr(Id::digest($id), 0, 12)) . '">Responder</a>'
            . $ownActions
            . '<div class="post-stats">' . $stats . '</div>'
            . $replyModal
            . '</footer>';
    }

    private function replyModal(string $id, string $csrf): string
    {
        $suffix = Html::escape(substr(Id::digest($id), 0, 12));
        $encodedId = Html::escape($id);

        return '<section id="reply-' . $suffix . '" class="modal-overlay" aria-label="Responder">'
            . '<a class="modal-backdrop" href="#" aria-label="Cerrar"></a>'
            . '<article class="compose-modal">'
            . '<header><h2>Responder</h2><a class="modal-close" href="#" aria-label="Cerrar">×</a></header>'
            . '<form method="post" action="?route=admin/post" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="inReplyTo" value="' . $encodedId . '"/>'
            . '<label>Texto <textarea name="content" rows="7" required></textarea></label>'
            . '<label>Visibilidad <select name="visibility"><option value="public">Pública</option><option value="followers">Seguidores</option></select></label>'
            . '<label>Imagen <input name="image_upload" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>Texto alt <textarea name="image_alt" rows="3"></textarea></label>'
            . '<div class="modal-actions"><button type="submit">Enviar</button><a class="button-link secondary" href="#">Cancelar</a></div>'
            . '</form>'
            . '</article>'
            . '</section>';
    }

    private function postAnchor(string $id): string
    {
        return 'post-' . substr(Id::digest($id), 0, 12);
    }

    private function currentPageWithAnchor(string $anchor): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? $this->publicUrl());
        $uri = preg_replace('/#.*$/', '', $uri) ?? $uri;

        return $uri . '#' . $anchor;
    }

    private function ownPostActions(array $object, ?array $actions): string
    {
        if ($actions === null) {
            return '';
        }

        $uid = (string)($actions['uid'] ?? '');
        $id = ActivityPub::objectId($object) ?? '';
        if ($uid === '' || $id === '') {
            return '';
        }

        $actor = ActivityPub::attributedTo($object) ?? '';
        if (!in_array($actor, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true)) {
            return '';
        }

        $csrf = Html::escape((string)($actions['csrf'] ?? ''));
        $encodedId = Html::escape($id);
        $suffix = Html::escape(substr(Id::digest($id), 0, 12));
        $source = Html::escape((string)($object['sourceContent'] ?? strip_tags((string)($object['content'] ?? ''))));

        return '<div class="own-post-actions">'
            . '<a class="button-link secondary" href="#edit-' . $suffix . '">Editar</a>'
            . '<a class="button-link secondary danger-link" href="#delete-' . $suffix . '">Borrar</a>'
            . '</div>'
            . '<section id="edit-' . $suffix . '" class="modal-overlay" aria-label="Editar publicación">'
            . '<a class="modal-backdrop" href="#" aria-label="Cancelar"></a>'
            . '<article class="compose-modal"><header><h2>Editar</h2><a class="modal-close" href="#" aria-label="Cancelar">×</a></header>'
            . '<form method="post" action="?route=admin/post-edit" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="id" value="' . $encodedId . '"/>'
            . '<label>Texto <textarea name="content" rows="7" required>' . $source . '</textarea></label>'
            . '<label>Imagen adjunta <input name="image_upload" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>Texto alt <textarea name="image_alt" rows="3"></textarea></label>'
            . '<div class="modal-actions"><button type="submit">Enviar</button><a class="button-link secondary" href="#">Cancelar</a></div>'
            . '</form></article></section>'
            . '<section id="delete-' . $suffix . '" class="modal-overlay" aria-label="Borrar publicación">'
            . '<a class="modal-backdrop" href="#" aria-label="No"></a>'
            . '<article class="compose-modal"><header><h2>Borrar</h2><a class="modal-close" href="#" aria-label="No">×</a></header>'
            . '<p>¿Seguro que quieres borrar esta publicación?</p>'
            . '<form method="post" action="?route=admin/post-delete">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="id" value="' . $encodedId . '"/>'
            . '<div class="modal-actions"><button type="submit" class="danger">Sí</button><a class="button-link secondary" href="#">No</a></div>'
            . '</form></article></section>';
    }

    private function interactionAvatars(string $label, array $actors): string
    {
        $avatars = '';

        foreach ($actors as $actorId) {
            if (!is_string($actorId) || $actorId === '') {
                continue;
            }

            $info = $this->actorInfo($actorId);
            $url = Html::escape($info['url']);
            $title = Html::escape($label . ': ' . $info['label']);
            $avatar = Html::escape($info['avatar']);
            $initial = Html::escape($info['initial']);
            $inner = $avatar !== ''
                ? '<img src="' . $avatar . '" alt=""/>'
                : '<span>' . $initial . '</span>';

            $avatars .= '<a class="interaction-avatar" href="' . $url . '" title="' . $title . '" aria-label="' . $title . '">' . $inner . '</a>';
        }

        if ($avatars === '') {
            return '';
        }

        return '<div class="interaction-group"><span>' . Html::escape($label) . '</span><div class="interaction-avatars">' . $avatars . '</div></div>';
    }

    private function childrenHtml(array $children, ?array $actions): string
    {
        if ($children === []) {
            return '';
        }

        $html = '<div class="reply-tree">';

        foreach ($children as $node) {
            if (is_array($node['object'] ?? null)) {
                $html .= $this->objectCard($node['object'], true, [
                    'children' => $node['children'] ?? [],
                    'actions' => $actions,
                ]);
            }
        }

        return $html . '</div>';
    }

    private function withMissingParents(array $objects): array
    {
        $byId = [];

        foreach ($objects as $object) {
            $id = is_array($object) ? ActivityPub::objectId($object) : null;
            if ($id !== null) {
                $byId[$id] = $object;
            }
        }

        $changed = true;
        $guard = 0;

        while ($changed && $guard++ < 5) {
            $changed = false;

            foreach ($byId as $object) {
                $parent = ActivityPub::inReplyTo($object);
                if ($parent === null || isset($byId[$parent])) {
                    continue;
                }

                $parentObject = $this->repo->findByIdOrAlias($parent);
                if ($parentObject !== null) {
                    $parentId = ActivityPub::objectId($parentObject);
                    if ($parentId !== null && !isset($byId[$parentId])) {
                        $byId[$parentId] = $parentObject;
                        $changed = true;
                    }
                }
            }
        }

        return array_values($byId);
    }

    private function treeFor(array $objects): array
    {
        $nodes = [];
        $order = [];

        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }

            $id = ActivityPub::objectId($object);
            if ($id === null) {
                continue;
            }

            $nodes[$id] = [
                'object' => $object,
                'children' => [],
            ];
            $order[] = $id;
        }

        foreach ($order as $id) {
            $parent = ActivityPub::inReplyTo($nodes[$id]['object']);
            $parent = $parent !== null ? $this->canonicalObjectId($parent) : null;
            if ($parent !== null && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = &$nodes[$id];
            }
        }
        unset($id);

        $roots = [];
        foreach ($order as $id) {
            $parent = ActivityPub::inReplyTo($nodes[$id]['object']);
            $parent = $parent !== null ? $this->canonicalObjectId($parent) : null;
            if ($parent === null || !isset($nodes[$parent])) {
                $roots[] = $nodes[$id];
            }
        }

        usort($roots, fn (array $a, array $b): int => strcmp(
            ActivityPub::published($b['object']),
            ActivityPub::published($a['object'])
        ));

        return $roots;
    }

    private function canonicalObjectId(string $id): string
    {
        if (isset($this->canonicalIdCache[$id])) {
            return $this->canonicalIdCache[$id];
        }

        $object = $this->repo->findByIdOrAlias($id);
        $canonical = is_array($object) ? (ActivityPub::objectId($object) ?? $id) : $id;

        return $this->canonicalIdCache[$id] = $canonical;
    }

    private function replyTree(string $id): array
    {
        $children = $this->replyDescendants($id);
        return $this->treeFor($children);
    }

    private function replyDescendants(string $id, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [];
        }

        $all = [];

        foreach ($this->repo->childrenOf($id) as $child) {
            if (!ActivityPub::isPublicObject($child)) {
                continue;
            }

            $all[] = $child;
            $childId = ActivityPub::objectId($child);

            if ($childId !== null) {
                $all = array_merge($all, $this->replyDescendants($childId, $depth + 1));
            }
        }

        return $all;
    }

    private function localTimeline(int $limit): array
    {
        $actorIds = [];
        $boosts = [];

        foreach ($this->users->all() as $uid => $_user) {
            if (is_string($uid)) {
                $actorIds[] = $this->users->actorId($uid);
                $actorIds = array_merge($actorIds, $this->users->legacyActorIds($uid));
                $boosts = array_merge($boosts, $this->interactions->boostedObjectsByUser($uid, $limit));
            }
        }

        $objects = array_merge(
            $this->repo->byAnyActor(array_values(array_unique($actorIds)), $limit * 3),
            $boosts,
        );

        return $this->sortTimelineObjects($this->publicObjects($objects), $limit);
    }

    private function publicObjects(array $objects): array
    {
        return array_values(array_filter($objects, static fn (array $object): bool => ActivityPub::isPublicObject($object)));
    }

    private function instancePresentationCard(InstanceSettings $settings): string
    {
        $name = Html::escape($settings->instanceName());
        $favicon = Html::escape($settings->faviconPath());
        $content = Html::safeContent($this->renderInstancePresentation($settings->presentationHtml(), $settings));

        return '<article class="object instance-card">'
            . '<header class="object-head">'
            . '<a class="post-avatar-link instance-avatar" href="' . Html::escape($this->publicUrl()) . '" aria-label="' . $name . '">'
            . '<img class="post-avatar instance-favicon-avatar" src="' . $favicon . '" alt=""/>'
            . '</a><div><p class="meta post-meta"><strong>' . $name . '</strong><br/>Presentación</p></div></header>'
            . '<div class="content">' . $content . '</div>'
            . '</article>';
    }

    private function renderInstancePresentation(string $html, InstanceSettings $settings): string
    {
        return strtr($html, [
            '$Nombre' => Html::escape($settings->instanceName()),
            '$Numero' => (string)count($this->users->all()),
            '$Avatares' => $this->instanceAvatarList(),
        ]);
    }

    private function instanceAvatarList(): string
    {
        $html = '<span class="instance-avatars">';

        foreach ($this->users->all() as $uid => $user) {
            if (!is_string($uid) || !is_array($user)) {
                continue;
            }

            $avatar = Html::escape($this->users->avatarUrl($user));
            $name = Html::escape((string)($user['name'] ?? $uid));
            $profile = Html::escape($this->users->webUrl($uid));
            $html .= '<a class="instance-avatar-link" href="' . $profile . '" title="' . $name . '">'
                . '<img src="' . $avatar . '" alt="' . $name . '"/></a>';
        }

        return $html . '</span>';
    }

    private function sortTimelineObjects(array $objects, int $limit): array
    {
        $unique = [];

        foreach ($objects as $object) {
            $id = ActivityPub::objectId($object);
            if ($id !== null) {
                $unique[$id . ':' . (string)($object['_oannes_boosted_at'] ?? '')] = $object;
            }
        }

        $objects = array_values($unique);
        usort($objects, static fn (array $a, array $b): int => strcmp(
            (string)($b['_oannes_boosted_at'] ?? ActivityPub::published($b)),
            (string)($a['_oannes_boosted_at'] ?? ActivityPub::published($a))
        ));

        return array_slice($objects, 0, $limit);
    }

    public function actorInfo(string $actorId): array
    {
        if (isset($this->actorInfoCache[$actorId])) {
            return $this->actorInfoCache[$actorId];
        }

        foreach ($this->users->all() as $uid => $user) {
            $ids = array_merge([$this->users->actorId((string)$uid)], $this->users->legacyActorIds((string)$uid));

            if (in_array($actorId, $ids, true)) {
                $name = (string)($user['name'] ?? $uid);

                return $this->actorInfoCache[$actorId] = [
                    'label' => $name !== '' ? $name . ' (@' . $uid . ')' : $actorId,
                    'avatar' => $this->users->avatarUrl($user),
                    'initial' => $this->initial((string)$uid),
                    'url' => $this->users->webUrl((string)$uid),
                    'internal_url' => $this->users->webUrl((string)$uid),
                ];
            }
        }

        $actor = $actorId !== '' ? $this->actors->findById($actorId) : null;
        $name = is_array($actor) ? (string)($actor['name'] ?? $actor['preferredUsername'] ?? $actorId) : $actorId;
        $avatar = '';

        if (is_array($actor)) {
            $icon = $actor['icon'] ?? null;
            if (is_array($icon) && is_string($icon['url'] ?? null)) {
                $avatar = $icon['url'];
            }
        }

        return $this->actorInfoCache[$actorId] = [
            'label' => $name !== '' ? $name : 'Autor desconocido',
            'avatar' => $avatar,
            'initial' => $this->initial($name !== '' ? $name : '?'),
            'url' => $this->actorUrl($actor, $actorId),
            'internal_url' => $actorId !== '' ? $this->publicUrl(['actor' => $actorId]) : '#',
        ];
    }

    public function localUserInfo(string $uid): array
    {
        return $this->actorInfo($this->users->actorId($uid));
    }

    private function actorUrl(?array $actor, string $actorId): string
    {
        if (is_array($actor)) {
            $url = $actor['url'] ?? null;

            if (is_string($url) && $url !== '') {
                return $url;
            }

            if (is_array($url)) {
                foreach ($url as $item) {
                    if (is_string($item) && $item !== '') {
                        return $item;
                    }

                    if (is_array($item) && is_string($item['href'] ?? null)) {
                        return $item['href'];
                    }
                }
            }
        }

        return $actorId !== '' ? $actorId : '#';
    }

    private function actorHandle(array $actor, string $actorId): string
    {
        $username = $actor['preferredUsername'] ?? null;
        $host = parse_url($actorId, PHP_URL_HOST);

        if (is_string($username) && $username !== '' && is_string($host) && $host !== '') {
            return '@' . $username . '@' . $host;
        }

        return $actorId;
    }

    private function initial(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($text, 0, 1));
    }

    private function titleFor(array $object): string
    {
        foreach (['name', 'summary'] as $field) {
            $value = $object[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $this->plainSnippet($value);
            }
        }

        $content = $object['content'] ?? '';
        if (is_string($content) && trim($content) !== '') {
            return $this->plainSnippet($content);
        }

        return ActivityPub::objectId($object) ?? 'Objeto';
    }

    private function plainSnippet(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($text)) ?? '');

        return mb_substr($text, 0, 120);
    }

    private function contentFor(array $object): string
    {
        $source = $object['sourceContent'] ?? null;
        if (is_string($source) && trim($source) !== '') {
            return Html::safeContent(nl2br($this->linkTextEntities($source), false));
        }

        $content = $object['content'] ?? '';
        if (is_string($content) && trim($content) !== '') {
            return Html::safeContent($this->linkUrlsInHtmlText($content));
        }

        return '<p class="muted">Sin contenido textual.</p>';
    }

    private function linkTextEntities(string $text): string
    {
        $pattern = '/(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})(?![\w@.-])|(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_-]{0,63})(?![\w@.-])|https?:\/\/[^\s<>"\']+/u';
        $html = '';
        $offset = 0;

        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $index => $match) {
            [$entity, $position] = $match;
            $html .= Html::escape(substr($text, $offset, $position - $offset));

            if (str_starts_with($entity, 'http://') || str_starts_with($entity, 'https://')) {
                [$url, $suffix] = $this->splitUrlSuffix($entity);
                $html .= '<a href="' . Html::escape($url) . '">' . Html::escape($url) . '</a>' . Html::escape($suffix);
                $offset = $position + strlen($entity);
                continue;
            }

            $localUsername = $matches[3][$index][0] ?? '';
            $url = $localUsername !== ''
                ? $this->mentionUrl($localUsername, (string)$this->config['host'])
                : $this->mentionUrl($matches[1][$index][0], $matches[2][$index][0]);
            $html .= $url !== null
                ? '<a class="mention" href="' . Html::escape($url) . '">' . Html::escape($entity) . '</a>'
                : Html::escape($entity);
            $offset = $position + strlen($entity);
        }

        return $html . Html::escape(substr($text, $offset));
    }

    private function splitUrlSuffix(string $url): array
    {
        $suffix = '';
        while ($url !== '' && preg_match('/[.,;:!?)]$/', $url) === 1) {
            $suffix = substr($url, -1) . $suffix;
            $url = substr($url, 0, -1);
        }

        return [$url, $suffix];
    }

    private function linkUrlsInHtmlText(string $html): string
    {
        $parts = preg_split('/(<[^>]+>)/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $html;
        }

        $linked = '';
        $insideLink = false;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                if (preg_match('/^<\s*a\b/i', $part) === 1) {
                    $insideLink = true;
                } elseif (preg_match('/^<\s*\/\s*a\s*>/i', $part) === 1) {
                    $insideLink = false;
                }

                $linked .= $part;
                continue;
            }

            $linked .= $insideLink ? $part : $this->linkUrlsInTextNode($part);
        }

        return $linked;
    }

    private function linkUrlsInTextNode(string $text): string
    {
        return preg_replace_callback(
            '/https?:\/\/[^\s<>"\']+/u',
            function (array $match): string {
                [$url, $suffix] = $this->splitUrlSuffix($match[0]);
                $href = html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . Html::escape($href) . '">' . $url . '</a>' . $suffix;
            },
            $text
        ) ?? $text;
    }

    private function mentionUrl(string $username, string $host): ?string
    {
        if (strcasecmp($host, (string)$this->config['host']) === 0 && $this->users->find($username) !== null) {
            return $this->users->webUrl($username);
        }

        foreach ($this->actors->findByPreferredUsername($username, $host) ?? [] as $actor) {
            return $this->actorUrl($actor, ActivityPub::objectId($actor) ?? '');
        }

        return null;
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

    private function assetUrl(string $asset): string
    {
        $path = (string)($this->config['public_path'] ?? '');

        if ($path === '') {
            return '/' . ltrim($asset, '/');
        }

        return rtrim(dirname($path), '/') . '/' . ltrim($asset, '/');
    }
}
