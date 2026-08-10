<?php

namespace Oannes;

final class AdminRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Auth $auth,
    ) {
    }

    public function login(?string $error = null): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';

        return $this->renderer->page('Acceso', '<section class="auth-card panel-narrow">'
            . '<h1>Acceso</h1>'
            . $errorHtml
            . '<form method="post" action="?route=admin/login">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>Usuario <input name="uid" autocomplete="username" required/></label>'
            . '<label>Clave <input name="password" type="password" autocomplete="current-password" required/></label>'
            . '<button type="submit">Entrar</button>'
            . '</form></section>');
    }

    public function setup(?string $error = null): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';

        return $this->renderer->page('Crear administrador', '<section class="auth-card panel-narrow">'
            . '<h1>Crear administrador</h1>'
            . $errorHtml
            . '<form method="post" action="?route=setup">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>Usuario <input name="uid" autocomplete="username" required/></label>'
            . '<label>Nombre <input name="name" autocomplete="name"/></label>'
            . '<label>Clave <input name="password" type="password" autocomplete="new-password" required/></label>'
            . '<button type="submit">Crear instancia</button>'
            . '</form></section>');
    }

    public function instanceAdmin(string $currentUid, array $users, array $settings, array $blockedServers, array $blockNotices, ?string $message = null, ?string $error = null, string $openBox = ''): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $messageHtml = $message !== null ? '<p class="notice">' . Html::escape($message) . '</p>' : '';
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';
        $instanceName = Html::escape((string)($settings['instance_name'] ?? ''));
        $presentationHtml = Html::escape((string)($settings['presentation_html'] ?? InstanceSettings::DEFAULT_PRESENTATION_HTML));
        $instanceHtml = '<form method="post" action="?route=instance-admin/settings" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>Nombre de Instancia <input name="instance_name" value="' . $instanceName . '" placeholder="Uanna"/></label>'
            . '<label>Presentación de instancia (html) <textarea name="presentation_html" rows="8">' . $presentationHtml . '</textarea></label>'
            . '<button type="submit">Guardar instancia</button>'
            . '</form>';
        $updateMode = (string)($settings['update_mode'] ?? 'activity');
        $updateMode = in_array($updateMode, ['activity', 'cron'], true) ? $updateMode : 'activity';
        $installDir = dirname(__DIR__, 3);
        $queueCommand = 'php oannes/bin/oannes.php queue-run 25';
        $inboxCommand = 'php oannes/bin/oannes.php inbox-run 25';
        $cronBlock = '* * * * * cd ' . $installDir . ' && ' . $queueCommand . ' >/dev/null 2>&1' . "\n"
            . '* * * * * cd ' . $installDir . ' && ' . $inboxCommand . ' >/dev/null 2>&1';
        $installCronCommand = '(crontab -l 2>/dev/null | grep -v "oannes/bin/oannes.php"; printf "%s\n" ' . escapeshellarg($cronBlock) . ') | crontab -';
        $updatesHelp = $updateMode === 'cron'
            ? '<div class="cron-help"><p class="meta">Comandos:</p><pre><code>' . Html::escape($queueCommand . "\n" . $inboxCommand) . '</code></pre>'
                . '<p class="meta">crontab recomendado para esta instalación, ejecutado cada minuto:</p><pre><code>' . Html::escape($cronBlock) . '</code></pre>'
                . '<p class="meta">Comando para guardar el crontab:</p><pre><code>' . Html::escape($installCronCommand) . '</code></pre></div>'
            : '<p class="meta">Este modo procesa pequeños lotes de recepción y envíos cuando hay visitas. Es más lento, pero no requiere configurar cron.</p>';
        $updatesHtml = '<form method="post" action="?route=instance-admin/settings" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label class="check-row"><input name="update_mode" type="radio" value="activity"' . ($updateMode === 'activity' ? ' checked' : '') . '/><span>Actualización al detectarse actividad (lenta)</span></label>'
            . '<label class="check-row"><input name="update_mode" type="radio" value="cron"' . ($updateMode === 'cron' ? ' checked' : '') . '/><span>Usar cron</span></label>'
            . $updatesHelp
            . '<button type="submit">Guardar actualizaciones</button>'
            . '</form>';
        $settingsHtml = '<form method="post" action="?route=instance-admin/settings" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>Favicon <input name="favicon" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>Avatar por defecto <input name="default_avatar" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>Cabecera por defecto <input name="default_header" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<button type="submit">Guardar imágenes</button>'
            . '</form>';
        $serversHtml = '<form method="post" action="?route=instance-admin/server-blocks" class="follow-new-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="action" value="add"/>'
            . '<label>Servidor bloqueado <input name="server" placeholder="servidor.org"/></label>'
            . '<button type="submit">Añadir</button>'
            . '</form><div class="actor-list">';

        foreach ($blockedServers as $server) {
            $serversHtml .= '<article class="actor-row"><span>' . Html::escape((string)$server) . '</span>'
                . '<form method="post" action="?route=instance-admin/server-blocks" class="actor-actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="action" value="delete"/>'
                . '<input type="hidden" name="server" value="' . Html::escape((string)$server) . '"/>'
                . '<button type="submit" class="danger">Borrar</button></form></article>';
        }
        $serversHtml .= '</div>';
        $noticesHtml = $blockNotices === [] ? '<p class="muted">Sin avisos de bloqueos de usuarios.</p>' : '<div class="notifications">';
        foreach ($blockNotices as $notice) {
            $actor = (string)($notice['actor'] ?? '');
            $blockedBy = is_array($notice['blocked_by'] ?? null) ? $notice['blocked_by'] : [];
            $noticesHtml .= '<article class="notification follow-request"><div><strong>' . Html::escape($actor) . '</strong>'
                . '<p class="meta">Bloqueado por: ' . Html::escape(implode(', ', $blockedBy)) . '</p></div>'
                . '<form method="post" action="?route=instance-admin/actor-block" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="actor" value="' . Html::escape($actor) . '"/>'
                . '<button type="submit">Bloquear en servidor</button></form></article>';
        }
        $noticesHtml .= $blockNotices === [] ? '' : '</div>';
        $createUsersHtml = '<form method="post" action="?route=instance-admin/users" class="form-grid">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="action" value="add"/>'
            . '<label>Usuario <input name="uid" required/></label>'
            . '<label>Nombre <input name="name"/></label>'
            . '<label>Clave <input name="password" type="password" required/></label>'
            . '<label class="check-row"><input name="admin" type="checkbox" value="1"/><span>Administrador</span></label>'
            . '<button type="submit">Crear usuario</button></form>';
        $editUsersHtml = '<div class="actor-list">';

        foreach ($users as $uid => $user) {
            $uid = (string)$uid;
            $isAdmin = (bool)($user['admin'] ?? false);
            $info = $this->renderer->localUserInfo($uid);
            $avatar = Html::escape((string)($info['avatar'] ?? ''));
            $displayName = Html::escape((string)($user['name'] ?? $uid));
            $avatarHtml = $avatar !== ''
                ? '<img class="mini-avatar" src="' . $avatar . '" alt=""/>'
                : '<span class="mini-avatar avatar-fallback">' . Html::escape(mb_strtoupper(mb_substr($uid, 0, 1))) . '</span>';
            $adminButton = $uid === $currentUid
                ? ''
                : '<button type="submit" name="action" value="' . ($isAdmin ? 'unset-admin' : 'set-admin') . '">' . ($isAdmin ? 'Quitar admin' : 'Hacer admin') . '</button>';
            $deleteButton = $uid === $currentUid
                ? ''
                : '<button type="submit" name="action" value="delete" class="danger">Borrar usuario</button>';

            $editUsersHtml .= '<article class="actor-row"><div class="user-admin-mini">' . $avatarHtml
                . '<span><strong>' . $displayName . '</strong><small>@' . Html::escape($uid) . ($isAdmin ? ' · admin' : '') . '</small></span></div>'
                . '<form method="post" action="?route=instance-admin/users" class="actor-actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="uid" value="' . Html::escape($uid) . '"/>'
                . '<input name="password" type="password" placeholder="Nueva clave"/>'
                . '<button type="submit" name="action" value="password">Clave</button>'
                . $adminButton
                . $deleteButton
                . '</form></article>';
        }
        $editUsersHtml .= '</div>';

        return $this->renderer->page('Panel de administración', $messageHtml . $errorHtml
            . $this->panelBox('Instancia', $instanceHtml)
            . $this->panelBox('Actualizaciones', $updatesHtml, $openBox === 'updates')
            . $this->panelBox('Imágenes de instancia', $settingsHtml)
            . $this->panelBox('Avisos de bloqueo', $noticesHtml)
            . $this->panelBox('Servidores bloqueados', $serversHtml)
            . $this->panelBox('Crear usuarios', $createUsersHtml)
            . $this->panelBox('Editar usuarios', $editUsersHtml));
    }

    public function dashboard(
        string $uid,
        array $pendingFollows = [],
        array $pendingCreates = [],
        ?string $message = null,
        ?string $error = null,
        array $timeline = [],
        ?array $profile = null,
        string $profileUrl = '',
        array $notifications = [],
        array $followers = [],
        array $following = [],
        array $privateMessages = [],
        array $socialStates = [],
        string $timelineSearchQuery = '',
        string $timelineSearchResults = ''
    ): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $messageHtml = $message !== null ? '<p class="notice">' . Html::escape($message) . '</p>' : '';
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';
        $profileHtml = $this->profileForm($uid, $profile ?? [], $csrf);
        $notificationsHtml = $this->notifications($notifications, $pendingFollows, $pendingCreates, $csrf);
        $socialHtml = $this->socialColumns($uid, $csrf, $followers, $following, $socialStates);
        $privateHtml = $this->privateMessages($privateMessages, $csrf);
        $profileLink = $profileUrl !== ''
            ? '<a class="button-link secondary" href="' . Html::escape($profileUrl) . '">Ver perfil</a>'
            : '';

        $logout = '<form method="post" action="?route=admin/logout" class="logout-form panel-logout">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<button type="submit">Salir</button>'
            . '</form>';

        $focus = $_GET['focus'] ?? '';
        $focus = is_string($focus) ? $focus : '';

        return $this->renderer->page('Panel de usuario', $messageHtml . $errorHtml
            . $this->panelBox('Perfil', '<div class="admin-actions">' . $profileLink . '</div>' . $profileHtml)
            . $this->panelBox('Buscar en timeline', $this->timelineSearch($timelineSearchQuery, $timelineSearchResults), $timelineSearchQuery !== '')
            . $this->panelBox('Notificaciones', $notificationsHtml, $focus === 'notifications', 'notifications')
            . $this->panelBox('Mensajes privados', $privateHtml)
            . $this->panelBox('Red', $socialHtml)
            . '<div class="panel-bottom-actions">' . $logout . '</div>');
    }

    private function panelBox(string $title, string $content, bool $open = false, string $id = ''): string
    {
        return '<details class="object admin-section"' . ($id !== '' ? ' id="' . Html::escape($id) . '"' : '') . ($open ? ' open' : '') . '>'
            . '<summary><span>' . Html::escape($title) . '</span></summary>'
            . '<div class="admin-section-body">' . $content . '</div>'
            . '</details>';
    }

    private function timelineSearch(string $query, string $results): string
    {
        return '<form method="get" action="" class="timeline-search-form">'
            . '<input type="hidden" name="route" value="admin"/>'
            . '<label>Buscar <input name="timeline_q" value="' . Html::escape($query) . '" placeholder="Texto del mensaje"/></label>'
            . '<button type="submit">Buscar</button>'
            . '</form>'
            . ($query !== '' ? '<div class="timeline-search-results">' . ($results !== '' ? $results : '<p class="muted">Sin resultados.</p>') . '</div>' : '');
    }

    private function profileForm(string $uid, array $profile, string $csrf): string
    {
        $name = Html::escape((string)($profile['name'] ?? $uid));
        $bio = Html::escape((string)($profile['bio'] ?? ''));
        $email = Html::escape((string)($profile['email'] ?? ''));
        $lang = Html::escape((string)($profile['lang'] ?? ''));
        $tz = Html::escape((string)($profile['tz'] ?? ''));
        $approve = (bool)($profile['approve_followers'] ?? true) ? ' checked' : '';
        $avatarPreview = Html::escape((string)($profile['avatar'] ?? ''));
        $headerPreview = Html::escape((string)($profile['header'] ?? ''));

        return '<form method="post" action="?route=admin/profile" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<div class="form-grid">'
            . '<label>Nombre <input name="name" value="' . $name . '" autocomplete="name"/></label>'
            . '<label>Email <input name="email" type="email" value="' . $email . '" autocomplete="email"/></label>'
            . '<label>Idioma <input name="lang" value="' . $lang . '"/></label>'
            . '<label>Zona horaria <input name="tz" value="' . $tz . '"/></label>'
            . '</div>'
            . '<div class="image-upload-grid">'
            . $this->imageCropper('avatar', 'Avatar', $avatarPreview, '1')
            . $this->imageCropper('header', 'Cabecera', $headerPreview, '3')
            . '</div>'
            . '<label>Bio <textarea name="bio" rows="5">' . $bio . '</textarea></label>'
            . '<label class="check-row"><input name="approve_followers" type="checkbox" value="1"' . $approve . '/><span>Aprobar seguidores manualmente</span></label>'
            . '<button type="submit">Guardar perfil</button>'
            . '</form>';
    }

    private function imageCropper(string $field, string $label, string $preview, string $aspect): string
    {
        $image = $preview !== '' ? '<img src="' . $preview . '" alt=""/>' : '<span>Sin imagen</span>';

        return '<section class="image-cropper" data-field="' . Html::escape($field) . '" data-aspect="' . Html::escape($aspect) . '">'
            . '<h3>' . Html::escape($label) . '</h3>'
            . '<div class="crop-preview">' . $image . '<canvas hidden></canvas></div>'
            . '<input type="hidden" name="' . Html::escape($field) . '_image"/>'
            . '<label>Subir imagen <input name="' . Html::escape($field) . '_upload" type="file" accept="image/png,image/jpeg,image/webp"/></label>'
            . '<label>Zoom <input class="crop-zoom" type="range" min="1" max="3" step="0.01" value="1"/></label>'
            . '<label>Horizontal <input class="crop-x" type="range" min="-1" max="1" step="0.01" value="0"/></label>'
            . '<label>Vertical <input class="crop-y" type="range" min="-1" max="1" step="0.01" value="0"/></label>'
            . '</section>';
    }

    private function notifications(array $notifications, array $pendingFollows, array $pendingCreates, string $csrf): string
    {
        $html = '';

        foreach ($pendingFollows as $case) {
            $record = is_array($case['record'] ?? null) ? $case['record'] : [];
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            $actor = $activity['actor'] ?? $record['actor'] ?? 'actor desconocido';
            $caseId = (string)($case['case_id'] ?? '');
            $actorHtml = is_string($actor) ? $this->actorLink($actor) : 'actor desconocido';

            $html .= '<article class="notification follow-request">'
                . '<div><strong>Nuevo seguidor</strong><p class="meta">' . $actorHtml . '</p></div>'
                . '<form method="post" action="?route=admin/moderation/follow" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">Aprobar</button>'
                . '<button name="decision" value="reject" type="submit">Descartar</button>'
                . '</form>'
                . '</article>';
        }

        foreach ($pendingCreates as $case) {
            $record = is_array($case['record'] ?? null) ? $case['record'] : [];
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            $actor = $activity['actor'] ?? $record['actor'] ?? 'actor desconocido';
            $object = is_array($activity['object'] ?? null) ? $activity['object'] : [];
            $objectId = (string)($object['id'] ?? '');
            $objectUrl = $this->objectUrl($object);
            $replyTo = ActivityPub::inReplyTo($object) ?? '';
            $caseId = (string)($case['case_id'] ?? '');
            $actorHtml = is_string($actor) ? $this->actorLink($actor) : 'actor desconocido';
            $objectHtml = $objectUrl !== ''
                ? '<a href="' . Html::escape($objectUrl) . '">' . Html::escape($objectUrl) . '</a>'
                : ($objectId !== '' ? '<a href="' . Html::escape($objectId) . '">' . Html::escape($objectId) . '</a>' : '');
            $replyHtml = $replyTo !== ''
                ? '<p class="meta">En respuesta a <a href="' . Html::escape($replyTo) . '">' . Html::escape($replyTo) . '</a></p>'
                : '';

            $html .= '<article class="notification follow-request">'
                . '<div><strong>Publicación pendiente</strong><p class="meta">' . $actorHtml . ($objectHtml !== '' ? ' publicó ' . $objectHtml : '') . '</p>'
                . $replyHtml
                . '</div>'
                . '<form method="post" action="?route=admin/moderation/create" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">Aprobar</button>'
                . '<button name="decision" value="reject" type="submit">Descartar</button>'
                . '</form>'
                . '</article>';
        }

        foreach ($notifications as $notification) {
            $date = (string)($notification['date'] ?? '');
            $type = (string)($notification['type'] ?? '');
            $actor = (string)($notification['actor'] ?? '');
            $objid = (string)($notification['objid'] ?? '');
            $body = $this->notificationBody($type, $actor, $objid);

            $html .= '<article class="notification">'
                . '<strong>' . Html::escape((string)($notification['label'] ?? 'Notificación')) . '</strong>'
                . $body
                . '<p class="meta"><time datetime="' . Html::escape($date) . '">' . Html::escape(DateFormat::human($date)) . '</time></p>'
                . '</article>';
        }

        return $html !== '' ? '<div class="notifications">' . $html . '</div>' : '<p class="muted">Sin notificaciones recientes.</p>';
    }

    private function notificationBody(string $type, string $actor, string $objid): string
    {
        if (in_array($type, ['Like', 'Announce'], true) && $actor !== '' && $objid !== '') {
            $verb = $type === 'Like' ? 'favoriteó' : 'impulsó';
            return '<p class="meta">' . $this->actorLink($actor) . ' ' . $verb . ' <a href="' . Html::escape($objid) . '">' . Html::escape($objid) . '</a></p>';
        }

        if ($type === 'Follow' && $actor !== '') {
            return '<p class="meta">' . $this->actorLink($actor) . '</p>';
        }

        if ($type === 'Create' && $actor !== '' && $objid !== '') {
            return '<p class="meta">' . $this->actorLink($actor) . ' respondió en <a href="' . Html::escape($objid) . '">' . Html::escape($objid) . '</a></p>';
        }

        if ($type === 'Webmention' && $actor !== '') {
            return '<p class="meta"><a href="' . Html::escape($actor) . '">' . Html::escape($actor) . '</a></p>';
        }

        return '<p class="meta">' . Html::escape($actor) . '</p>';
    }

    private function actorLink(string $actorId): string
    {
        $info = $this->renderer->actorInfo($actorId);
        $label = (string)($info['label'] ?? $actorId);
        $url = (string)($info['url'] ?? $actorId);

        return '<a href="' . Html::escape($url) . '">' . Html::escape($label !== '' ? $label : $actorId) . '</a>';
    }

    private function objectUrl(array $object): string
    {
        $url = $object['url'] ?? null;

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

        return ActivityPub::objectId($object) ?? '';
    }

    private function socialColumns(string $uid, string $csrf, array $followers, array $following, array $socialStates): string
    {
        return '<form method="post" action="?route=admin/social" class="follow-new-form">'
            . '<input type="hidden" name="csrf" value="' . Html::escape($csrf) . '"/>'
            . '<input type="hidden" name="action" value="follow-new"/>'
            . '<label>Seguir nuevo usuario <input name="actor_query" placeholder="@usuario@servidor.org o https://..." required/></label>'
            . '<button type="submit">Seguir</button>'
            . '</form>'
            . '<div class="social-columns">'
            . '<section><h3>Seguidores <span class="social-count">' . count($followers) . '</span></h3>' . $this->actorList($uid, $csrf, $followers, $socialStates, false) . '</section>'
            . '<section><h3>Seguidos <span class="social-count">' . count($following) . '</span></h3>' . $this->actorList($uid, $csrf, $following, $socialStates, true) . '</section>'
            . '</div>';
    }

    private function actorList(string $uid, string $csrf, array $actors, array $socialStates, bool $showUnfollow): string
    {
        if ($actors === []) {
            return '<p class="muted">Sin registros.</p>';
        }

        $html = '<div class="actor-list">';

        foreach ($actors as $actor) {
            if (!is_array($actor)) {
                continue;
            }

            $actorId = is_string($actor['id'] ?? null) ? $actor['id'] : '';
            $resolved = $actorId !== '' ? $this->renderer->actorInfo($actorId) : [];
            $name = (string)($resolved['label'] ?? $actor['name'] ?? $actor['preferredUsername'] ?? $actor['id'] ?? 'Actor');
            $id = (string)($resolved['url'] ?? $actor['url'] ?? $actor['id'] ?? '#');
            $avatar = (string)($resolved['avatar'] ?? '');
            $state = is_array($socialStates[$actorId] ?? null) ? $socialStates[$actorId] : [];
            $isFollowing = (bool)($state['following'] ?? false);
            $isMuted = (bool)($state['muted'] ?? false);
            $isBlocked = (bool)($state['blocked'] ?? false);
            $icon = $actor['icon'] ?? null;

            if ($avatar === '' && is_array($icon) && is_string($icon['url'] ?? null)) {
                $avatar = $icon['url'];
            }

            $initial = Html::escape(mb_strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1)));
            $avatarHtml = $avatar !== ''
                ? '<img class="mini-avatar" src="' . Html::escape($avatar) . '" alt=""/>'
                : '<span class="mini-avatar avatar-fallback">' . $initial . '</span>';

            $actions = $actorId !== ''
                ? $this->socialActions($csrf, $actorId, $isFollowing, $isMuted, $isBlocked, $showUnfollow)
                : '';

            $html .= '<article class="actor-row">'
                . '<a class="actor-main" href="' . Html::escape($id) . '">'
                . $avatarHtml
                . '<span>' . Html::escape($name) . '</span>'
                . '</a>'
                . $actions
                . '</article>';
        }

        return $html . '</div>';
    }

    private function socialActions(string $csrf, string $actorId, bool $isFollowing, bool $isMuted, bool $isBlocked, bool $showUnfollow): string
    {
        $html = '<form method="post" action="?route=admin/social" class="actor-actions">'
            . '<input type="hidden" name="csrf" value="' . Html::escape($csrf) . '"/>'
            . '<input type="hidden" name="actor" value="' . Html::escape($actorId) . '"/>';

        if (!$isFollowing) {
            $html .= '<button type="submit" name="action" value="follow">Seguir</button>';
        } else {
            $html .= '<button type="submit" name="action" value="' . ($isMuted ? 'unmute' : 'mute') . '">'
                . ($isMuted ? 'Quitar silencio' : 'Silenciar')
                . '</button>';
            if ($showUnfollow) {
                $html .= '<button type="submit" name="action" value="unfollow">No seguir</button>';
            }
        }

        $html .= '<button type="submit" name="action" value="' . ($isBlocked ? 'unblock' : 'block') . '" class="danger">'
            . ($isBlocked ? 'Desbloquear' : 'Bloquear')
            . '</button>'
            . '</form>';

        return $html;
    }

    private function privateMessages(array $messages, string $csrf): string
    {
        $html = '<form method="post" action="?route=admin/private-message" class="private-compose">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>Destinatario <input name="to" type="url" placeholder="https://..." required/></label>'
            . '<label>Mensaje <textarea name="content" rows="5" required></textarea></label>'
            . '<button type="submit">Enviar privado</button>'
            . '</form>';

        if ($messages === []) {
            return $html . '<p class="muted">Sin mensajes privados recientes.</p>';
        }

        $html .= '<div class="private-list">';

        foreach ($this->privateMessageTree($messages) as $node) {
            $html .= '<article class="private-dialog">' . $this->privateMessageNode($node) . '</article>';
        }

        return $html . '</div>';
    }

    private function privateMessageTree(array $messages): array
    {
        $nodes = [];
        $order = [];

        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }

            $id = is_string($message['id'] ?? null) && $message['id'] !== ''
                ? $message['id']
                : 'private:' . count($nodes);
            $nodes[$id] = [
                'message' => $message,
                'children' => [],
            ];
            $order[] = $id;
        }

        foreach ($order as $id) {
            $parent = is_string($nodes[$id]['message']['inReplyTo'] ?? null) ? $nodes[$id]['message']['inReplyTo'] : '';

            if ($parent !== '' && $parent !== $id && isset($nodes[$parent])) {
                $nodes[$parent]['children'][] = &$nodes[$id];
            }
        }
        unset($id);

        $roots = [];
        foreach ($order as $id) {
            $parent = is_string($nodes[$id]['message']['inReplyTo'] ?? null) ? $nodes[$id]['message']['inReplyTo'] : '';
            if ($parent === '' || !isset($nodes[$parent])) {
                $roots[] = $nodes[$id];
            }
        }

        return $roots;
    }

    private function privateMessageNode(array $node): string
    {
        $message = is_array($node['message'] ?? null) ? $node['message'] : [];
        $actor = (string)($message['actor'] ?? '');
        $info = $actor !== '' ? $this->renderer->actorInfo($actor) : [
            'label' => 'Remitente desconocido',
            'url' => '#',
            'avatar' => '',
            'initial' => '?',
        ];
        $published = (string)($message['published'] ?? '');
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $childrenHtml = '';
        $avatar = (string)($info['avatar'] ?? '');
        $initial = (string)($info['initial'] ?? '?');
        $avatarHtml = $avatar !== ''
            ? '<img class="private-avatar" src="' . Html::escape($avatar) . '" alt=""/>'
            : '<span class="private-avatar avatar-fallback">' . Html::escape($initial) . '</span>';

        foreach ($children as $child) {
            if (is_array($child)) {
                $childrenHtml .= $this->privateMessageNode($child);
            }
        }

        return '<article class="private-message">'
            . '<header class="private-message-head">'
            . '<time datetime="' . Html::escape($published) . '">' . Html::escape(DateFormat::human($published)) . '</time>'
            . '<a class="private-sender" href="' . Html::escape((string)$info['url']) . '" title="' . Html::escape((string)$info['label']) . '" aria-label="' . Html::escape((string)$info['label']) . '">' . $avatarHtml . '</a>'
            . '</header>'
            . '<div class="content">' . Html::safeContent((string)($message['content'] ?? '')) . '</div>'
            . ($childrenHtml !== '' ? '<div class="private-replies">' . $childrenHtml . '</div>' : '')
            . '</article>';
    }

    private function followReviews(array $pendingFollows, string $csrf): string
    {
        if ($pendingFollows === []) {
            return '<p class="muted">No hay solicitudes de seguimiento pendientes.</p>';
        }

        $html = '';

        foreach ($pendingFollows as $case) {
            $record = is_array($case['record'] ?? null) ? $case['record'] : [];
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            $actor = $activity['actor'] ?? $record['actor'] ?? 'actor desconocido';
            $caseId = (string)($case['case_id'] ?? '');

            $html .= '<article class="review">'
                . '<p><strong>Solicitud Follow</strong></p>'
                . '<p class="meta">' . Html::escape(is_string($actor) ? $actor : 'actor desconocido') . '</p>'
                . '<form method="post" action="?route=admin/moderation/follow" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">Aprobar</button>'
                . '<button name="decision" value="reject" type="submit">Rechazar</button>'
                . '</form>'
                . '</article>';
        }

        return $html;
    }

    private function createReviews(array $pendingCreates, string $csrf): string
    {
        if ($pendingCreates === []) {
            return '<p class="muted">No hay publicaciones remotas pendientes.</p>';
        }

        $html = '';

        foreach ($pendingCreates as $case) {
            $record = is_array($case['record'] ?? null) ? $case['record'] : [];
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            $object = is_array($activity['object'] ?? null) ? $activity['object'] : [];
            $actor = $activity['actor'] ?? $record['actor'] ?? 'actor desconocido';
            $content = is_string($object['content'] ?? null) ? Html::safeContent((string)$object['content']) : '';
            $caseId = (string)($case['case_id'] ?? '');

            $html .= '<article class="review">'
                . '<p><strong>Create</strong></p>'
                . '<p class="meta">' . Html::escape(is_string($actor) ? $actor : 'actor desconocido') . '</p>'
                . ($content !== '' ? '<div class="content">' . $content . '</div>' : '<p class="muted">Sin contenido visible.</p>')
                . '<form method="post" action="?route=admin/moderation/create" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">Aprobar</button>'
                . '<button name="decision" value="reject" type="submit">Rechazar</button>'
                . '</form>'
                . '</article>';
        }

        return $html;
    }
}
