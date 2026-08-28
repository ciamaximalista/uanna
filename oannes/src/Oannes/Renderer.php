<?php

namespace Oannes;

final class Renderer
{
    private readonly LocalUsers $users;
    private readonly ActorRepository $actors;
    private readonly InteractionService $interactions;
    private readonly I18n $i18n;
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
        $this->i18n = new I18n($store, $this->config);
    }

    public function t(string $key, string $fallback = '', array $params = []): string
    {
        return $this->i18n->t($key, $fallback, $params);
    }

    public function page(string $title, string $body): string
    {
        $settings = new InstanceSettings(new FileStore($this->config['data_dir']), $this->config);
        $instanceName = $settings->instanceName();
        $language = Html::escape($this->i18n->language());
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
            . '<html lang="' . $language . '"><head><meta charset="utf-8"/>'
            . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"/>"
            . "<title>{$pageTitle}</title>"
            . "<link rel=\"icon\" type=\"image/png\" href=\"{$favicon}\"/>"
            . "<link rel=\"apple-touch-icon\" href=\"{$favicon}\"/>"
            . "<link rel=\"stylesheet\" href=\"{$style}\"/>"
            . "<script defer src=\"{$cropScript}\"></script>"
            . "</head><body><header class=\"topbar\">{$brand}"
            . "<nav class=\"navlinks\"><a href=\"{$home}\">" . Html::escape($this->t('nav.home', 'Inicio')) . "</a>{$panelLink}{$adminLink}{$composer['button']}</nav></header>"
            . "<main>{$body}</main>{$footer}{$composer['modal']}</body></html>";
    }

    private function panelNavLink(): string
    {
        $href = Html::escape($this->publicUrl(['route' => 'admin']));
        $auth = new Auth(new FileStore($this->config['data_dir']));
        $uid = $auth->currentUser();

        if ($uid === null) {
            return '<a href="' . $href . '">' . Html::escape($this->t('nav.panel', 'Panel')) . '</a>';
        }

        $count = $this->panelAttentionCount($uid);
        if ($count <= 0) {
            return '<a href="' . $href . '">' . Html::escape($this->t('nav.panel', 'Panel')) . '</a>';
        }

        $target = Html::escape($this->publicUrl(['route' => 'admin', 'focus' => 'notifications']) . '#notifications');
        return '<a class="panel-link has-badge" href="' . $target . '">' . Html::escape($this->t('nav.panel', 'Panel')) . ' <span class="nav-badge">' . Html::escape((string)min($count, 99)) . '</span></a>';
    }

    private function panelAttentionCount(string $uid): int
    {
        return $this->unreadNotificationCount($uid) + $this->pendingReviewCount($uid);
    }

    private function unreadNotificationCount(string $uid): int
    {
        $seen = $this->notificationsSeenAt($uid);
        $count = 0;

        foreach (glob((string)$this->config['data_dir'] . '/users/' . rawurlencode($uid) . '/notify/*.json') ?: [] as $file) {
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
        return '<footer class="site-footer"><p>' . $this->t('footer.made_with', '{name} se hace con {uanna}, software libre con licencia {license} desarrollado por {company} para {ruralnext}.', [
            'name' => $name,
            'uanna' => '<a href="https://ruralnext.org/uanna" target="_blank" rel="noopener">Uanna</a>',
            'license' => '<a href="https://interoperable-europe.ec.europa.eu/collection/eupl/eupl-text-eupl-12" target="_blank" rel="noopener">EUPL 1.2<img class="site-footer-logo eupl-logo" src="' . Html::escape($this->assetUrl('EUPL-logo-04.png')) . '" alt=""/></a>',
            'company' => '<a href="https://maximalista.coop" target="_blank" rel="noopener">Compañía Maximalista S.Coop<img class="site-footer-logo" src="' . Html::escape($this->assetUrl('maximalista.png')) . '" alt=""/></a>',
            'ruralnext' => '<a href="https://ruralnext.org" target="_blank" rel="noopener">RuralNEXT</a>',
        ]) . '</p></footer>';
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

        return '<a href="' . Html::escape($this->publicUrl(['route' => 'instance-admin'])) . '">' . Html::escape($this->t('nav.admin', 'Admin')) . '</a>';
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
        $reloadUrl = Html::escape($this->reloadUrl());
        $reloadIcon = '<svg aria-hidden="true" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>';

        return [
            'button' => '<a class="compose-trigger" href="#compose-modal" aria-label="' . Html::escape($this->t('post.publish', 'Publicar')) . '">+</a>'
                . '<a class="reload-trigger" href="' . $reloadUrl . '" aria-label="' . Html::escape($this->t('actions.reload', 'Recargar')) . '" title="' . Html::escape($this->t('actions.reload', 'Recargar')) . '">' . $reloadIcon . '</a>',
            'modal' => '<section id="compose-modal" class="modal-overlay" aria-label="' . Html::escape($this->t('post.publish', 'Publicar')) . '">'
                . '<a class="modal-backdrop" href="#" aria-label="' . Html::escape($this->t('actions.close', 'Cerrar')) . '"></a>'
                . '<article class="compose-modal">'
                . '<header><h2>' . Html::escape($this->t('post.publish', 'Publicar')) . '</h2><a class="modal-close" href="#" aria-label="' . Html::escape($this->t('actions.close', 'Cerrar')) . '">×</a></header>'
                . '<form method="post" action="?route=admin/post" enctype="multipart/form-data">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<label>' . Html::escape($this->t('field.text', 'Texto')) . ' <textarea name="content" rows="7" required></textarea></label>'
                . '<label>' . Html::escape($this->t('field.reply_to', 'Responder a')) . ' <input name="inReplyTo" type="url" placeholder="https://..."/></label>'
                . '<label>' . Html::escape($this->t('field.visibility', 'Visibilidad')) . ' <select name="visibility"><option value="public">' . Html::escape($this->t('visibility.public', 'Pública')) . '</option><option value="followers">' . Html::escape($this->t('visibility.followers_only', 'Sólo para seguidores')) . '</option></select></label>'
                . $this->postImageInputs()
                . '<label>' . Html::escape($this->t('field.alt_texts', 'Textos alt, uno por línea')) . ' <textarea name="image_alt" rows="4"></textarea></label>'
                . '<button type="submit">' . Html::escape($this->t('post.publish', 'Publicar')) . '</button>'
                . '</form>'
                . '</article>'
                . '</section>',
        ];
    }

    private function postImageInputs(array $attachments = []): string
    {
        $label = Html::escape($this->t('field.images', 'Imágenes'));
        $html = '<div class="post-image-inputs" aria-label="' . $label . '">';
        $visibleCount = min(4, max(1, count($attachments) + 1));

        for ($i = 0; $i < 4; $i++) {
            $class = $i < $visibleCount ? 'post-image-slot is-visible' : 'post-image-slot';
            $existing = is_array($attachments[$i] ?? null) ? $attachments[$i] : null;
            $existingInput = $existing !== null
                ? '<input type="hidden" name="existing_attachment[]" value="' . Html::escape(base64_encode(Json::encode($existing))) . '"/>'
                : '<input type="hidden" name="existing_attachment[]" value=""/>';
            $html .= '<label class="' . $class . '">' . ($i === 0 ? $label : Html::escape($this->t('field.image_extra', 'Otra imagen')))
                . ' <input name="image_upload[]" type="file" accept="image/*"/>'
                . $existingInput
                . ($existing !== null ? $this->existingAttachmentLabel($existing) : '')
                . '</label>';
        }

        return $html . '</div>';
    }

    private function existingAttachmentLabel(array $attachment): string
    {
        $url = $this->attachmentUrl($attachment);
        if ($url === '') {
            return '';
        }

        $alt = $this->attachmentAlt($attachment);
        $label = $alt !== '' ? $alt : basename((string)(parse_url($url, PHP_URL_PATH) ?: $url));

        return '<span class="existing-attachment">' . Html::escape($this->t('attachment.current', 'Imagen actual')) . ': '
            . '<a href="' . Html::escape($url) . '" target="_blank" rel="noopener">' . Html::escape($label) . '</a></span>';
    }

    private function reloadUrl(): string
    {
        return $this->publicUrl(['timeline_reload' => time()]);
    }

    private function profileSocialAction(string $actorId): string
    {
        if ($actorId === '') {
            return '';
        }

        $store = new FileStore($this->config['data_dir']);
        $auth = new Auth($store);
        $uid = $auth->currentUser();
        if ($uid === null) {
            return '';
        }

        if (in_array($actorId, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true)) {
            return '';
        }

        $graph = new SocialGraph($store);
        $isFollowing = $graph->isFollowing($uid, $actorId);
        $followsYou = $graph->isFollower($uid, $actorId);
        $action = $isFollowing ? 'unfollow' : 'follow';
        $label = $isFollowing ? $this->t('actions.unfollow', 'No seguir') : $this->t('actions.follow', 'Seguir');

        return '<div class="profile-social-controls">'
            . ($followsYou ? '<span class="profile-follow-badge">' . Html::escape($this->t('profile.follows_you', 'Te sigue')) . '</span>' : '')
            . '<form method="post" action="' . Html::escape($this->publicUrl(['route' => 'admin/social'])) . '" class="profile-social-action">'
            . '<input type="hidden" name="csrf" value="' . Html::escape($auth->csrfToken()) . '"/>'
            . '<input type="hidden" name="actor" value="' . Html::escape($actorId) . '"/>'
            . '<input type="hidden" name="action" value="' . Html::escape($action) . '"/>'
            . '<input type="hidden" name="return_to" value="' . Html::escape($this->currentPage()) . '"/>'
            . '<button type="submit">' . Html::escape($label) . '</button>'
            . '</form>'
            . '</div>';
    }

    private function followedByPeopleYouFollow(string $targetActorId): string
    {
        $targetActorId = trim($targetActorId);
        if ($targetActorId === '') {
            return '';
        }

        $store = new FileStore($this->config['data_dir']);
        $auth = new Auth($store);
        $viewerUid = $auth->currentUser();
        if ($viewerUid === null) {
            return '';
        }

        $viewerActorIds = array_merge([$this->users->actorId($viewerUid)], $this->users->legacyActorIds($viewerUid));
        if (in_array($targetActorId, $viewerActorIds, true)) {
            return '';
        }

        $graph = new SocialGraph($store);
        $viewerFollowing = [];
        foreach ($graph->following($viewerUid) as $actor) {
            foreach (ActivityPub::aliases($actor) as $alias) {
                $viewerFollowing[$alias] = $actor;
            }
        }

        if ($viewerFollowing === []) {
            return '';
        }

        $matches = [];
        foreach ($this->users->all() as $uid => $_user) {
            if (!is_string($uid) || $uid === $viewerUid) {
                continue;
            }

            $candidateIds = array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid));
            $isFollowedByViewer = false;
            foreach ($candidateIds as $candidateId) {
                if (isset($viewerFollowing[$candidateId])) {
                    $isFollowedByViewer = true;
                    break;
                }
            }

            if ($isFollowedByViewer && $graph->isFollowing($uid, $targetActorId)) {
                $matches[$this->users->actorId($uid)] = $this->actorInfo($this->users->actorId($uid));
            }
        }

        $localTargetUid = $this->localUidForActor($targetActorId);
        if ($localTargetUid !== null) {
            foreach ($graph->followers($localTargetUid) as $actor) {
                $actorId = ActivityPub::objectId($actor);
                if ($actorId === null) {
                    continue;
                }

                foreach (ActivityPub::aliases($actor) as $alias) {
                    if (isset($viewerFollowing[$alias])) {
                        $matches[$actorId] = $this->actorInfo($actorId);
                        break;
                    }
                }
            }
        }

        if ($matches === []) {
            return '';
        }

        $html = '';
        foreach (array_slice($matches, 0, 8, true) as $info) {
            $url = Html::escape((string)($info['internal_url'] ?? $info['url'] ?? '#'));
            $label = Html::escape((string)($info['label'] ?? ''));
            $avatar = Html::escape((string)($info['avatar'] ?? ''));
            $initial = Html::escape((string)($info['initial'] ?? '?'));
            $avatarHtml = $avatar !== ''
                ? '<img src="' . $avatar . '" alt=""/>'
                : '<span class="followed-by-avatar avatar-fallback">' . $initial . '</span>';
            $html .= '<a class="followed-by-avatar-link" href="' . $url . '" title="' . $label . '" aria-label="' . $label . '">' . $avatarHtml . '</a>';
        }

        return '<p class="profile-followed-by"><span>' . Html::escape($this->t('profile.followed_by', 'Seguido por')) . '</span>' . $html . '</p>';
    }

    private function localUidForActor(string $actorId): ?string
    {
        foreach ($this->users->all() as $uid => $_user) {
            if (!is_string($uid)) {
                continue;
            }

            if (in_array($actorId, array_merge([$this->users->actorId($uid)], $this->users->legacyActorIds($uid)), true)) {
                return $uid;
            }
        }

        return null;
    }

    private function currentPage(): string
    {
        $uri = (string)($_SERVER['REQUEST_URI'] ?? $this->publicUrl());
        if ($uri === '' || str_contains($uri, "\r") || str_contains($uri, "\n") || str_starts_with($uri, '//')) {
            return $this->publicUrl();
        }

        return preg_replace('/#.*$/', '', $uri) ?? $uri;
    }

    private function profileEmailHtml(string $profileUid, string $email): string
    {
        $email = trim($email);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '';
        }

        $store = new FileStore($this->config['data_dir']);
        $auth = new Auth($store);
        $viewerUid = $auth->currentUser();
        if ($viewerUid === null) {
            return '';
        }

        $viewerActorIds = array_merge([$this->users->actorId($viewerUid)], $this->users->legacyActorIds($viewerUid));
        if ($this->anyActorBlockedByInstance($viewerActorIds)) {
            return '';
        }

        if ($viewerUid !== $profileUid) {
            $graph = new SocialGraph($store);
            $relations = new SocialRelationService($store);
            $allowed = false;

            foreach ($viewerActorIds as $actorId) {
                if ($graph->isFollowing($profileUid, $actorId)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed || $relations->isAnyBlocked($profileUid, $viewerActorIds)) {
                return '';
            }
        }

        return '<p class="profile-email"><a href="mailto:' . Html::escape($email) . '">' . Html::escape($email) . '</a></p>';
    }

    private function anyActorBlockedByInstance(array $actorIds): bool
    {
        $settings = new InstanceSettings(new FileStore($this->config['data_dir']), $this->config);
        foreach ($actorIds as $actorId) {
            if (is_string($actorId) && $actorId !== '' && $settings->isActorBlocked($actorId)) {
                return true;
            }
        }

        return false;
    }

    public function home(): string
    {
        $settings = new InstanceSettings(new FileStore($this->config['data_dir']), $this->config);
        $pageSize = $this->timelinePageSize();
        $objects = $this->localTimeline($pageSize + 1);
        $hasMore = count($objects) > $pageSize;
        $objects = array_slice($objects, 0, $pageSize);
        $timeline = $this->objectList($objects);
        $nextUrl = $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'public', 'offset' => $pageSize]) : '';

        return $this->page('', '<section class="timeline">' . $this->instancePresentationCard($settings) . $timeline . $this->timelineMore($nextUrl) . '</section>');
    }

    public function privateTimelinePage(string $uid, array $objects, string $csrf, string $nextUrl = ''): string
    {
        $items = $objects !== []
            ? $this->objectList($objects, false, [
                'actions' => [
                    'uid' => $uid,
                    'csrf' => $csrf,
                ],
            ])
            : '<p class="muted">' . Html::escape($this->t('timeline.empty_following', 'Todavía no hay publicaciones importadas de las cuentas que sigues.')) . '</p>';

        return $this->page('', '<section class="timeline">' . $items . $this->timelineMore($nextUrl) . '</section>');
    }

    public function userPage(string $uid, ?array $actions = null): string
    {
        $localUsers = new LocalUsers(new FileStore($this->config['data_dir']), $this->config);
        $user = $localUsers->find($uid);

        if ($user === null) {
            http_response_code(404);
            return $this->page($this->t('page.not_found', 'No encontrado'), '<h1>' . Html::escape($this->t('page.not_found', 'No encontrado')) . '</h1>');
        }

        $pageSize = $this->timelinePageSize();
        $objects = $this->userTimelineObjects($uid, 0, $pageSize + 1);
        $hasMore = count($objects) > $pageSize;
        $objects = array_slice($objects, 0, $pageSize);
        $items = $this->profileTimeline($objects, $actions);
        $nextUrl = $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'user', 'user' => $uid, 'offset' => $pageSize]) : '';

        $name = Html::escape((string)($user['name'] ?? $uid));
        $bio = Html::escape((string)($user['bio'] ?? ''));
        $emailHtml = $this->profileEmailHtml($uid, (string)($user['email'] ?? ''));
        $host = Html::escape((string)$this->config['host']);
        $avatar = Html::escape($localUsers->avatarUrl($user));
        $header = Html::escape((string)($user['header'] ?? '') ?: $localUsers->defaultHeaderUrl());
        $avatarHtml = '<img class="avatar" src="' . $avatar . '" alt=""/>';
        $headerStyle = $header !== '' ? ' style="background-image:url(\'' . $header . '\')"' : '';
        $profileActorId = $localUsers->actorId($uid);
        $profileActions = $this->profileSocialAction($profileActorId);
        $followedByHtml = $this->followedByPeopleYouFollow($profileActorId);

        $body = '<section class="profile hero-profile">'
            . '<div class="profile-cover"' . $headerStyle . '></div>'
            . '<div class="profile-main">' . $avatarHtml . '<div><h1>' . $name . '</h1>'
            . '<p class="meta">@' . Html::escape($uid) . '@' . $host . '</p>' . $followedByHtml . '</div>' . $profileActions . '</div>'
            . ($bio !== '' ? '<p class="bio">' . nl2br($bio) . '</p>' : '')
            . $emailHtml
            . '</section><section class="timeline">' . $items . $this->timelineMore($nextUrl) . '</section>';

        return $this->page($name, $body);
    }

    public function userTimelineChunk(string $uid, ?array $actions, int $offset, int $limit): array
    {
        $objects = $this->userTimelineObjects($uid, $offset, $limit + 1);
        $hasMore = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);

        return [
            'html' => $objects !== [] ? $this->profileTimeline($objects, $actions) : '',
            'next' => $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'user', 'user' => $uid, 'offset' => $offset + $limit]) : '',
        ];
    }

    public function actorPage(string $actorId, ?array $actions = null): string
    {
        $actorId = trim($actorId);
        $actor = $actorId !== '' ? $this->actors->findById($actorId) : null;

        if ($actorId === '' || $actor === null || $this->actorBlocked($actorId)) {
            http_response_code(404);
            return $this->page($this->t('page.not_found', 'No encontrado'), '<h1>' . Html::escape($this->t('page.not_found', 'No encontrado')) . '</h1>');
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
        $pageSize = $this->timelinePageSize();
        $objects = $this->actorTimelineObjects($actorId, 0, $pageSize + 1);
        $hasMore = count($objects) > $pageSize;
        $objects = array_slice($objects, 0, $pageSize);
        $items = $this->profileTimeline($objects, $actions);
        $nextUrl = $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'actor', 'actor' => $actorId, 'offset' => $pageSize]) : '';
        $name = Html::escape((string)$info['label']);
        $handle = Html::escape($this->actorHandle($actor, $actorId));
        $avatar = Html::escape((string)$info['avatar']);
        $avatarHtml = $avatar !== ''
            ? '<img class="avatar" src="' . $avatar . '" alt=""/>'
            : '<span class="avatar avatar-fallback">' . Html::escape((string)$info['initial']) . '</span>';
        $externalUrl = Html::escape((string)$info['url']);
        $profileActions = $this->profileSocialAction($actorId);

        $body = '<section class="profile hero-profile">'
            . '<div class="profile-cover"></div>'
            . '<div class="profile-main"><a href="' . $externalUrl . '">' . $avatarHtml . '</a><div><h1>' . $name . '</h1>'
            . ($handle !== '' ? '<p class="meta">' . $handle . '</p>' : '')
            . $this->followedByPeopleYouFollow($actorId)
            . '<p class="meta"><a href="' . $externalUrl . '">' . Html::escape($this->t('profile.original', 'Perfil original')) . '</a></p></div>' . $profileActions . '</div>'
            . '</section><section class="timeline">' . $items . $this->timelineMore($nextUrl) . '</section>';

        return $this->page((string)$info['label'], $body);
    }

    public function actorTimelineChunk(string $actorId, ?array $actions, int $offset, int $limit): array
    {
        $objects = $this->actorTimelineObjects($actorId, $offset, $limit + 1);
        $hasMore = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);

        return [
            'html' => $objects !== [] ? $this->profileTimeline($objects, $actions) : '',
            'next' => $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'actor', 'actor' => $actorId, 'offset' => $offset + $limit]) : '',
        ];
    }

    public function tagPage(string $tag, array $objects, ?array $actions, string $nextUrl = ''): string
    {
        $label = '#' . $tag;
        $items = $objects !== []
            ? $this->objectList($objects, false, ['actions' => $actions])
            : '<p class="muted">' . Html::escape($this->t('timeline.empty_tag', 'Todavía no hay publicaciones con esta etiqueta.')) . '</p>';

        return $this->page($label, '<section class="timeline tag-timeline"><h1>' . Html::escape($label) . '</h1>' . $items . $this->timelineMore($nextUrl) . '</section>');
    }

    public function timelineChunk(array $objects, ?array $actions): string
    {
        return $objects !== [] ? $this->objectList($objects, false, ['actions' => $actions]) : '';
    }

    public function publicTimelineChunk(int $offset, int $limit): array
    {
        $objects = $this->localTimeline($limit + 1, $offset);
        $hasMore = count($objects) > $limit;
        $objects = array_slice($objects, 0, $limit);

        return [
            'html' => $this->timelineChunk($objects, null),
            'next' => $hasMore ? $this->publicUrl(['route' => 'timeline-more', 'scope' => 'public', 'offset' => $offset + $limit]) : '',
        ];
    }

    public function threadedObjectList(array $objects, ?array $actions): string
    {
        return $objects !== [] ? $this->profileTimeline($objects, $actions) : '';
    }

    private function userTimelineObjects(string $uid, int $offset, int $limit): array
    {
        $localUsers = new LocalUsers(new FileStore($this->config['data_dir']), $this->config);
        $fetchLimit = max($limit, $offset + $limit);
        $objects = array_merge(
            $this->repo->byAnyActor(array_merge([$localUsers->actorId($uid)], $localUsers->legacyActorIds($uid)), $fetchLimit),
            $this->interactions->boostedObjectsByUser($uid, $fetchLimit),
        );

        return $this->sortObjectsForProfile($this->publicObjects($objects), $offset, $limit);
    }

    private function actorTimelineObjects(string $actorId, int $offset, int $limit): array
    {
        $actor = $this->actors->findById($actorId);
        if ($actor === null) {
            return [];
        }

        $fetchLimit = max($limit, $offset + $limit);
        return $this->sortObjectsForProfile(
            $this->publicObjects($this->repo->byAnyActor(ActivityPub::aliases($actor), $fetchLimit)),
            $offset,
            $limit
        );
    }

    private function sortObjectsForProfile(array $objects, int $offset = 0, int $limit = 80): array
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

        return array_slice($objects, $offset, $limit);
    }

    private function profileTimeline(array $objects, ?array $actions): string
    {
        $html = '';
        $renderedRoots = [];
        $lastDay = null;

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

                $html .= $this->timelineDaySeparator($node['object'], $lastDay, $this->threadActivityDate($node));
                $html .= $this->objectCard($node['object'], false, [
                    'children' => $node['children'] ?? [],
                    'actions' => $actions,
                ]);
            }
        }

        return $html !== '' ? $html : '<p class="muted">' . Html::escape($this->t('timeline.empty_public', 'Todavía no hay publicaciones públicas.')) . '</p>';
    }

    private function timelinePageSize(): int
    {
        return max(20, min(200, (int)($this->config['timeline_page_size'] ?? 80)));
    }

    private function timelineMore(string $nextUrl): string
    {
        if ($nextUrl === '') {
            return '';
        }

        return '<div class="timeline-more" data-next-url="' . Html::escape($nextUrl) . '">'
            . '<button type="button">' . Html::escape($this->t('timeline.load_more', 'Cargar más')) . '</button>'
            . '</div>';
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
            if ($parentObject === null || !ActivityPub::isPublicObject($parentObject) || $this->objectBlocked($parentObject)) {
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

        if ($object === null || !ActivityPub::isPublicObject($object) || $this->objectBlocked($object)) {
            http_response_code(404);
            return $this->page($this->t('page.not_found', 'No encontrado'), '<h1>' . Html::escape($this->t('page.not_found', 'No encontrado')) . '</h1>');
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
        $lastDay = null;

        foreach ($tree as $node) {
            if (is_array($node['object'] ?? null)) {
                if (!$child) {
                    $html .= $this->timelineDaySeparator($node['object'], $lastDay, $this->threadActivityDate($node));
                }
                $html .= $this->objectCard($node['object'], $child, [
                    'children' => $node['children'] ?? [],
                    'actions' => $options['actions'] ?? null,
                ]);
            }
        }

        return $html;
    }

    private function timelineDaySeparator(array $object, ?string &$lastDay, ?string $date = null): string
    {
        $date = is_string($date) && $date !== '' ? $date : $this->timelineSortDate($object);
        $timezone = (string)($this->config['timezone'] ?? 'Europe/Madrid');
        $day = DateFormat::dayKey($date, $timezone);
        if ($day === '') {
            return '';
        }

        if ($lastDay === null) {
            $lastDay = $day;
            return '';
        }

        if ($day === $lastDay) {
            return '';
        }

        $lastDay = $day;
        $label = DateFormat::day($date, $timezone);

        return '<div class="timeline-day-separator" aria-label="' . Html::escape($label) . '"><span>' . Html::escape($label) . '</span></div>';
    }

    private function timelineSortDate(array $object): string
    {
        $boostedAt = $object['_oannes_boosted_at'] ?? null;
        return is_string($boostedAt) && $boostedAt !== '' ? $boostedAt : ActivityPub::published($object);
    }

    private function objectCard(array $object, bool $child, array $options = []): string
    {
        if ($this->objectBlocked($object)) {
            return '';
        }

        $id = ActivityPub::objectId($object) ?? '';
        $actor = ActivityPub::attributedTo($object) ?? '';
        $actorInfo = $this->actorInfo($actor);
        $published = ActivityPub::published($object);
        $publishedHuman = DateFormat::human($published, (string)($this->config['timezone'] ?? 'Europe/Madrid'));
        $content = $this->contentFor($object);
        $attachments = $this->attachmentHtml($object);
        $boostedAt = is_string($object['_oannes_boosted_at'] ?? null) ? $object['_oannes_boosted_at'] : '';
        $boostedBy = is_string($object['_oannes_boosted_by'] ?? null) ? $object['_oannes_boosted_by'] : '';
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
        $copyUrlHtml = $url !== '' ? $this->copyPostUrlButton($url) : '';

        $boostHtml = $boostedAt !== '' ? $this->boostMarker($boostedBy, $boostedAt, $boostedHuman) : '';

        return '<article class="' . $class . '"' . $anchorAttr . '>'
            . $boostHtml
            . '<header class="object-head">' . $avatarHtml . '<div>'
            . '<p class="meta post-meta">' . $actorNameHtml . '<br/>'
            . '<span class="post-meta-line"><a href="' . Html::escape($url) . '"><time datetime="' . Html::escape($published) . '">' . Html::escape($publishedHuman) . '</time></a>' . $copyUrlHtml . $visibilityBadge . '</span></p></div></header>'
            . '<div class="content">' . $content . '</div>'
            . $attachments
            . $actionHtml
            . $childrenHtml
            . '</article>';
    }

    private function copyPostUrlButton(string $url): string
    {
        $label = Html::escape($this->t('actions.copy_post_url', 'Copiar URL del post'));

        return '<button type="button" class="copy-post-url" data-copy-url="' . Html::escape($url) . '" title="' . $label . '" aria-label="' . $label . '">'
            . '<svg aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            . '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>'
            . '</svg>'
            . '</button>';
    }

    private function attachmentHtml(array $object): string
    {
        $attachments = $object['attachment'] ?? [];
        $attachments = is_array($attachments) && array_is_list($attachments) ? $attachments : [$attachments];
        $objectId = ActivityPub::objectId($object) ?? '';
        $postAnchor = $objectId !== '' ? $this->postAnchor($objectId) : '';
        $html = '';

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $mediaType = is_string($attachment['mediaType'] ?? null) ? strtolower($attachment['mediaType']) : '';
            if (!str_starts_with($mediaType, 'image/')) {
                continue;
            }

            $url = $this->attachmentUrl($attachment);
            if ($url === '') {
                continue;
            }

            $alt = $this->attachmentAlt($attachment);

            $safeUrl = Html::escape($url);
            $downloadUrl = Html::escape($this->publicUrl(['route' => 'attachment-download', 'url' => $url]));
            $modalId = 'attachment-' . substr(Id::digest($objectId . "\n" . $url), 0, 16);
            $safeModalId = Html::escape($modalId);
            $backHref = $postAnchor !== '' ? '#' . Html::escape($postAnchor) : '#';
            $safeAlt = Html::escape($alt);
            $html .= '<p class="post-attachment">'
                . '<a href="#' . $safeModalId . '">' . Html::escape($this->t('attachment.image', 'Imagen adjunta')) . '</a>'
                . ($alt !== '' ? ': ' . $safeAlt : '')
                . '</p>'
                . '<section id="' . $safeModalId . '" class="modal-overlay attachment-modal-overlay" aria-label="' . Html::escape($this->t('attachment.image', 'Imagen adjunta')) . '">'
                . '<a class="modal-backdrop" href="' . $backHref . '" aria-label="' . Html::escape($this->t('actions.back', 'Volver')) . '"></a>'
                . '<article class="attachment-modal">'
                . '<figure>'
                . '<img src="' . $safeUrl . '" alt="' . $safeAlt . '"/>'
                . ($alt !== '' ? '<figcaption>' . $safeAlt . '</figcaption>' : '')
                . '</figure>'
                . '<nav class="attachment-modal-actions" aria-label="' . Html::escape($this->t('attachment.actions', 'Acciones de imagen')) . '">'
                . '<a class="button-link" href="' . $downloadUrl . '">' . Html::escape($this->t('actions.download', 'Descargar')) . '</a>'
                . '<a class="button-link secondary" href="' . $backHref . '">' . Html::escape($this->t('actions.back', 'Volver')) . '</a>'
                . '</nav>'
                . '</article>'
                . '</section>';
        }

        return $html !== '' ? '<div class="post-attachments">' . $html . '</div>' : '';
    }

    private function attachmentUrl(array $attachment): string
    {
        $url = $attachment['url'] ?? $attachment['href'] ?? null;
        if (is_string($url) && $url !== '') {
            return $url;
        }

        if (is_array($url)) {
            foreach ($url as $item) {
                if (is_string($item) && $item !== '') {
                    return $item;
                }

                if (is_array($item) && is_string($item['href'] ?? null) && $item['href'] !== '') {
                    return $item['href'];
                }
            }
        }

        return '';
    }

    private function attachmentAlt(array $attachment): string
    {
        foreach (['name', 'summary'] as $field) {
            if (is_string($attachment[$field] ?? null) && trim($attachment[$field]) !== '') {
                return trim($attachment[$field]);
            }
        }

        return '';
    }

    private function imageAttachments(array $object): array
    {
        $attachments = $object['attachment'] ?? [];
        $attachments = is_array($attachments) && array_is_list($attachments) ? $attachments : [$attachments];
        $images = [];

        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }

            $mediaType = is_string($attachment['mediaType'] ?? null) ? strtolower($attachment['mediaType']) : '';
            if (!str_starts_with($mediaType, 'image/') || $this->attachmentUrl($attachment) === '') {
                continue;
            }

            $images[] = $attachment;
        }

        return array_slice($images, 0, 4);
    }

    private function attachmentAltLines(array $attachments): string
    {
        return implode("\n", array_map(fn (array $attachment): string => $this->attachmentAlt($attachment), $attachments));
    }

    private function boostMarker(string $actorId, string $boostedAt, string $boostedHuman): string
    {
        $info = $this->actorInfo($actorId);
        $url = Html::escape((string)($info['internal_url'] ?? $info['url'] ?? '#'));
        $label = Html::escape($this->actorHandleLabel($actorId, $info));
        $avatar = Html::escape((string)($info['avatar'] ?? ''));
        $initial = Html::escape((string)($info['initial'] ?? '?'));
        $avatarHtml = $avatar !== ''
            ? '<img class="boost-avatar" src="' . $avatar . '" alt=""/>'
            : '<span class="boost-avatar avatar-fallback">' . $initial . '</span>';

        return '<p class="boost-marker">'
            . '<span>' . Html::escape($this->t('post.boosted_by', 'Impulsado por')) . ' <a href="' . $url . '">' . $label . '</a></span> '
            . '<time datetime="' . Html::escape($boostedAt) . '">' . Html::escape($boostedHuman) . '</time> '
            . '<a class="boost-avatar-link" href="' . $url . '" aria-label="' . $label . '">' . $avatarHtml . '</a>'
            . '</p>';
    }

    private function actorHandleLabel(string $actorId, array $info): string
    {
        $host = parse_url($actorId, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            $actor = $this->actors->findById($actorId);
            $preferred = is_array($actor) && is_string($actor['preferredUsername'] ?? null)
                ? $actor['preferredUsername']
                : '';

            if ($preferred !== '') {
                return '@' . $preferred . '@' . $host;
            }
        }

        return (string)($info['label'] ?? $actorId);
    }

    private function visibilityBadge(array $object): string
    {
        if (ActivityPub::isPublicObject($object)) {
            return '';
        }

        foreach (ActivityPub::audience($object) as $target) {
            if (str_ends_with($target, '/followers')) {
                return '<span class="visibility-badge followers">' . Html::escape($this->t('visibility.followers_only', 'Sólo para seguidores')) . '</span>';
            }
        }

        return '<span class="visibility-badge private">' . Html::escape($this->t('visibility.private', 'Privado')) . '</span>';
    }

    private function actionBar(string $id, array $interactionActors, ?array $actions, string $ownActions = ''): string
    {
        $stats = $this->interactionAvatars($this->t('stats.favorites', 'Favoritos'), $interactionActors['likes'] ?? [])
            . $this->interactionAvatars($this->t('stats.boosts', 'Impulsos'), $interactionActors['boosts'] ?? []);

        if ($actions === null || $id === '') {
            return '<footer class="post-actions post-stats">' . $stats . $ownActions . '</footer>';
        }

        $csrf = Html::escape((string)($actions['csrf'] ?? ''));
        $encodedId = Html::escape($id);
        $uid = (string)($actions['uid'] ?? '');
        $liked = $uid !== '' && $this->interactions->hasLocalReactionForCanonicalId($uid, $id, 'Like');
        $boosted = $uid !== '' && $this->interactions->hasLocalReactionForCanonicalId($uid, $id, 'Announce');
        $likeLabel = $liked ? $this->t('actions.unfavorite', 'Quitar fav') : $this->t('actions.favorite', 'Favoritear');
        $boostLabel = $boosted ? $this->t('actions.unboost', 'Quitar impulso') : $this->t('actions.boost', 'Impulsar');
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
            . '<a class="button-link reply-link" href="#reply-' . Html::escape(substr(Id::digest($id), 0, 12)) . '">' . Html::escape($this->t('actions.reply', 'Responder')) . '</a>'
            . $ownActions
            . '<div class="post-stats">' . $stats . '</div>'
            . $replyModal
            . '</footer>';
    }

    private function replyModal(string $id, string $csrf): string
    {
        $suffix = Html::escape(substr(Id::digest($id), 0, 12));
        $encodedId = Html::escape($id);

        return '<section id="reply-' . $suffix . '" class="modal-overlay" aria-label="' . Html::escape($this->t('actions.reply', 'Responder')) . '">'
            . '<a class="modal-backdrop" href="#" aria-label="' . Html::escape($this->t('actions.close', 'Cerrar')) . '"></a>'
            . '<article class="compose-modal">'
            . '<header><h2>' . Html::escape($this->t('actions.reply', 'Responder')) . '</h2><a class="modal-close" href="#" aria-label="' . Html::escape($this->t('actions.close', 'Cerrar')) . '">×</a></header>'
            . '<form method="post" action="?route=admin/post" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="inReplyTo" value="' . $encodedId . '"/>'
            . '<label>' . Html::escape($this->t('field.text', 'Texto')) . ' <textarea name="content" rows="7" required></textarea></label>'
            . '<label>' . Html::escape($this->t('field.visibility', 'Visibilidad')) . ' <select name="visibility"><option value="public">' . Html::escape($this->t('visibility.public', 'Pública')) . '</option><option value="followers">' . Html::escape($this->t('visibility.followers_only', 'Sólo para seguidores')) . '</option></select></label>'
            . $this->postImageInputs()
            . '<label>' . Html::escape($this->t('field.alt_texts', 'Textos alt, uno por línea')) . ' <textarea name="image_alt" rows="4"></textarea></label>'
            . '<div class="modal-actions"><button type="submit">' . Html::escape($this->t('actions.send', 'Enviar')) . '</button><a class="button-link secondary" href="#">' . Html::escape($this->t('actions.cancel', 'Cancelar')) . '</a></div>'
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
        $attachments = $this->imageAttachments($object);
        $altText = Html::escape($this->attachmentAltLines($attachments));

        return '<div class="own-post-actions">'
            . '<a class="button-link secondary" href="#edit-' . $suffix . '">' . Html::escape($this->t('actions.edit', 'Editar')) . '</a>'
            . '<a class="button-link secondary danger-link" href="#delete-' . $suffix . '">' . Html::escape($this->t('actions.delete', 'Borrar')) . '</a>'
            . '</div>'
            . '<section id="edit-' . $suffix . '" class="modal-overlay" aria-label="' . Html::escape($this->t('post.edit', 'Editar publicación')) . '">'
            . '<a class="modal-backdrop" href="#" aria-label="' . Html::escape($this->t('actions.cancel', 'Cancelar')) . '"></a>'
            . '<article class="compose-modal"><header><h2>' . Html::escape($this->t('actions.edit', 'Editar')) . '</h2><a class="modal-close" href="#" aria-label="' . Html::escape($this->t('actions.cancel', 'Cancelar')) . '">×</a></header>'
            . '<form method="post" action="?route=admin/post-edit" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="id" value="' . $encodedId . '"/>'
            . '<label>' . Html::escape($this->t('field.text', 'Texto')) . ' <textarea name="content" rows="7" required>' . $source . '</textarea></label>'
            . $this->postImageInputs($attachments)
            . '<label>' . Html::escape($this->t('field.alt_texts', 'Textos alt, uno por línea')) . ' <textarea name="image_alt" rows="4">' . $altText . '</textarea></label>'
            . '<div class="modal-actions"><button type="submit">' . Html::escape($this->t('actions.send', 'Enviar')) . '</button><a class="button-link secondary" href="#">' . Html::escape($this->t('actions.cancel', 'Cancelar')) . '</a></div>'
            . '</form></article></section>'
            . '<section id="delete-' . $suffix . '" class="modal-overlay" aria-label="' . Html::escape($this->t('post.delete', 'Borrar publicación')) . '">'
            . '<a class="modal-backdrop" href="#" aria-label="' . Html::escape($this->t('actions.no', 'No')) . '"></a>'
            . '<article class="compose-modal"><header><h2>' . Html::escape($this->t('actions.delete', 'Borrar')) . '</h2><a class="modal-close" href="#" aria-label="' . Html::escape($this->t('actions.no', 'No')) . '">×</a></header>'
            . '<p>' . Html::escape($this->t('post.delete_confirm', '¿Seguro que quieres borrar esta publicación?')) . '</p>'
            . '<form method="post" action="?route=admin/post-delete">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="id" value="' . $encodedId . '"/>'
            . '<div class="modal-actions"><button type="submit" class="danger">' . Html::escape($this->t('actions.yes', 'Sí')) . '</button><a class="button-link secondary" href="#">' . Html::escape($this->t('actions.no', 'No')) . '</a></div>'
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
                if ($parentObject !== null && !$this->objectBlocked($parentObject)) {
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
        foreach ($nodes as &$node) {
            $this->sortReplyNodesChronologically($node['children']);
        }
        unset($node);

        $roots = [];
        foreach ($order as $id) {
            $parent = ActivityPub::inReplyTo($nodes[$id]['object']);
            $parent = $parent !== null ? $this->canonicalObjectId($parent) : null;
            if ($parent === null || !isset($nodes[$parent])) {
                $roots[] = $nodes[$id];
            }
        }

        usort($roots, fn (array $a, array $b): int => strcmp(
            $this->threadActivityDate($b),
            $this->threadActivityDate($a)
        ));

        return $roots;
    }

    private function sortReplyNodesChronologically(array &$nodes): void
    {
        usort($nodes, fn (array $a, array $b): int => strcmp(
            ActivityPub::published($a['object']),
            ActivityPub::published($b['object'])
        ));

        foreach ($nodes as &$node) {
            if (is_array($node['children'] ?? null)) {
                $this->sortReplyNodesChronologically($node['children']);
            }
        }
        unset($node);
    }

    private function sortRootReplyNodesChronologically(array &$nodes): void
    {
        usort($nodes, fn (array $a, array $b): int => strcmp(
            ActivityPub::published($a['object']),
            ActivityPub::published($b['object'])
        ));
    }

    private function threadActivityDate(array $node): string
    {
        $object = is_array($node['object'] ?? null) ? $node['object'] : [];
        $latest = $this->timelineSortDate($object);

        foreach ((array)($node['children'] ?? []) as $child) {
            if (!is_array($child)) {
                continue;
            }

            $childDate = $this->threadActivityDate($child);
            if ($childDate > $latest) {
                $latest = $childDate;
            }
        }

        return $latest;
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
        $tree = $this->treeFor($children);
        $this->sortRootReplyNodesChronologically($tree);
        return $tree;
    }

    private function replyDescendants(string $id, int $depth = 0): array
    {
        if ($depth >= 8) {
            return [];
        }

        $all = [];

        foreach ($this->repo->childrenOf($id) as $child) {
            if (!ActivityPub::isPublicObject($child) || $this->objectBlocked($child)) {
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

    private function localTimeline(int $limit, int $offset = 0): array
    {
        $actorIds = [];
        $boosts = [];
        $fetchLimit = max($limit, $offset + $limit);

        foreach ($this->users->all() as $uid => $_user) {
            if (is_string($uid)) {
                $actorIds[] = $this->users->actorId($uid);
                $actorIds = array_merge($actorIds, $this->users->legacyActorIds($uid));
                $boosts = array_merge($boosts, $this->interactions->boostedObjectsByUser($uid, $fetchLimit));
            }
        }

        $objects = array_merge(
            $this->repo->byAnyActor(array_values(array_unique($actorIds)), $fetchLimit * 3),
            $boosts,
        );

        return $this->sortTimelineObjects($this->publicObjects($objects), $limit, $offset);
    }

    private function publicObjects(array $objects): array
    {
        return array_values(array_filter($objects, fn (array $object): bool => ActivityPub::isPublicObject($object) && !$this->objectBlocked($object)));
    }

    private function objectBlocked(array $object): bool
    {
        $actor = ActivityPub::attributedTo($object);
        return $actor !== null && $this->actorBlocked($actor);
    }

    private function actorBlocked(string $actorId): bool
    {
        return (new InstanceSettings(new FileStore($this->config['data_dir']), $this->config))->isActorBlocked($actorId);
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
            . '</a><div><p class="meta post-meta"><strong>' . $name . '</strong><br/>' . Html::escape($this->t('home.presentation', 'Presentación')) . '</p></div></header>'
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

    private function sortTimelineObjects(array $objects, int $limit, int $offset = 0): array
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

        return array_slice($objects, $offset, $limit);
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
            $avatar = $this->actorIconUrl($actor, $actorId);
        }

        return $this->actorInfoCache[$actorId] = [
            'label' => $name !== '' ? $name : $this->t('post.unknown_author', 'Autor desconocido'),
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

    private function actorIconUrl(array $actor, string $actorId): string
    {
        $icons = $actor['icon'] ?? null;
        if (is_array($icons) && !isset($icons['url'])) {
            foreach ($icons as $icon) {
                if (is_array($icon)) {
                    $url = $this->absoluteActorUrl($this->actorUrlValue($icon['url'] ?? $icon['href'] ?? null), $actorId);
                    if ($url !== '') {
                        return $url;
                    }
                }
            }

            return '';
        }

        if (is_array($icons)) {
            return $this->absoluteActorUrl($this->actorUrlValue($icons['url'] ?? $icons['href'] ?? null), $actorId);
        }

        return '';
    }

    private function actorUrlValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (!is_array($value)) {
            return '';
        }

        if (is_string($value['href'] ?? null)) {
            return $value['href'];
        }

        foreach ($value as $item) {
            $url = $this->actorUrlValue($item);
            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    private function absoluteActorUrl(string $url, string $actorId): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, 'data:')) {
            return $url;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $scheme = parse_url($actorId, PHP_URL_SCHEME);
        $host = parse_url($actorId, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host) || $scheme === '' || $host === '') {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        $path = parse_url($actorId, PHP_URL_PATH);
        $base = is_string($path) && $path !== '' ? rtrim(dirname($path), '/') : '';
        return $scheme . '://' . $host . ($base !== '' ? $base : '') . '/' . $url;
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

        return ActivityPub::objectId($object) ?? $this->t('post.object', 'Objeto');
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

        return '<p class="muted">' . Html::escape($this->t('post.no_text_content', 'Sin contenido textual.')) . '</p>';
    }

    private function linkTextEntities(string $text): string
    {
        $pattern = '/(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_.-]{0,63})@([A-Za-z0-9.-]+\.[A-Za-z]{2,})(?![\w@.-])|(?<![\w@])@([A-Za-z0-9_][A-Za-z0-9_-]{0,63})(?![\w@.-])|https?:\/\/[^\s<>"\']+|(?<![\p{L}\p{N}_&])#([\p{L}\p{N}_][\p{L}\p{N}_-]{0,63})(?![\p{L}\p{N}_-])/u';
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

            if (str_starts_with($entity, '#')) {
                $html .= $this->hashtagLink($entity);
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
            '/https?:\/\/[^\s<>"\']+|(?<![\p{L}\p{N}_&])#([\p{L}\p{N}_][\p{L}\p{N}_-]{0,63})(?![\p{L}\p{N}_-])/u',
            function (array $match): string {
                if (str_starts_with($match[0], '#')) {
                    return $this->hashtagLink($match[0]);
                }

                [$url, $suffix] = $this->splitUrlSuffix($match[0]);
                $href = html_entity_decode($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                return '<a href="' . Html::escape($href) . '">' . $url . '</a>' . $suffix;
            },
            $text
        ) ?? $text;
    }

    private function hashtagLink(string $tag): string
    {
        $name = ltrim($tag, '#');
        return '<a class="hashtag" href="' . Html::escape($this->publicUrl(['tag' => $name])) . '">' . Html::escape('#' . $name) . '</a>';
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
        $assetPath = ltrim($asset, '/');
        $publicDir = rtrim((string)($this->config['public_dir'] ?? dirname(__DIR__, 2) . '/public'), '/');
        $file = $publicDir . '/' . $assetPath;
        $version = is_file($file) ? '?v=' . (string)filemtime($file) : '';

        if ($path === '') {
            return '/' . $assetPath . $version;
        }

        return rtrim(dirname($path), '/') . '/' . $assetPath . $version;
    }
}
