<?php

namespace Oannes;

final class AdminRenderer
{
    public function __construct(
        private readonly Renderer $renderer,
        private readonly Auth $auth,
    ) {
    }

    private function t(string $key, string $fallback = '', array $params = []): string
    {
        return $this->renderer->t($key, $fallback, $params);
    }

    public function login(?string $error = null): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';

        return $this->renderer->page($this->t('auth.login', 'Acceso'), '<section class="auth-card panel-narrow">'
            . '<h1>' . Html::escape($this->t('auth.login', 'Acceso')) . '</h1>'
            . $errorHtml
            . '<form method="post" action="?route=admin/login">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('field.user', 'Usuario')) . ' <input name="uid" autocomplete="username" required/></label>'
            . '<label>' . Html::escape($this->t('field.password', 'Clave')) . ' <input name="password" type="password" autocomplete="current-password" required/></label>'
            . '<button type="submit">' . Html::escape($this->t('actions.login', 'Entrar')) . '</button>'
            . '</form></section>');
    }

    public function setup(?string $error = null): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';

        return $this->renderer->page($this->t('setup.create_admin', 'Crear administrador'), '<section class="auth-card panel-narrow">'
            . '<h1>' . Html::escape($this->t('setup.create_admin', 'Crear administrador')) . '</h1>'
            . $errorHtml
            . '<form method="post" action="?route=setup">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('field.user', 'Usuario')) . ' <input name="uid" autocomplete="username" required/></label>'
            . '<label>' . Html::escape($this->t('field.name', 'Nombre')) . ' <input name="name" autocomplete="name"/></label>'
            . '<label>' . Html::escape($this->t('field.password', 'Clave')) . ' <input name="password" type="password" autocomplete="new-password" required/></label>'
            . '<button type="submit">' . Html::escape($this->t('setup.create_instance', 'Crear instancia')) . '</button>'
            . '</form></section>');
    }

    public function instanceAdmin(string $currentUid, array $users, array $settings, array $blockedServers, array $blockedActors, array $blockNotices, ?string $message = null, ?string $error = null, string $openBox = '', array $appBuild = [], array $languages = []): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $messageHtml = $message !== null ? '<p class="notice">' . Html::escape($message) . '</p>' : '';
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';
        $instanceName = Html::escape((string)($settings['instance_name'] ?? ''));
        $presentationHtml = Html::escape((string)($settings['presentation_html'] ?? InstanceSettings::DEFAULT_PRESENTATION_HTML));
        $defaultLanguage = (string)($settings['default_language'] ?? '');
        $instanceHtml = '<form method="post" action="?route=instance-admin/settings" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('admin.instance_name', 'Nombre de Instancia')) . ' <input name="instance_name" value="' . $instanceName . '" placeholder="Uanna"/></label>'
            . '<label>' . Html::escape($this->t('admin.default_language', 'Idioma por defecto')) . ' ' . $this->languageSelect('default_language', $defaultLanguage, $languages) . '</label>'
            . '<label>' . Html::escape($this->t('admin.presentation_html', 'Presentación de instancia (html)')) . ' <textarea name="presentation_html" rows="8">' . $presentationHtml . '</textarea></label>'
            . '<button type="submit">' . Html::escape($this->t('admin.save_instance', 'Guardar instancia')) . '</button>'
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
            ? '<div class="cron-help"><p class="meta">' . Html::escape($this->t('admin.commands', 'Comandos:')) . '</p><pre><code>' . Html::escape($queueCommand . "\n" . $inboxCommand) . '</code></pre>'
                . '<p class="meta">' . Html::escape($this->t('admin.crontab_recommended', 'crontab recomendado para esta instalación, ejecutado cada minuto:')) . '</p><pre><code>' . Html::escape($cronBlock) . '</code></pre>'
                . '<p class="meta">' . Html::escape($this->t('admin.crontab_save_command', 'Comando para guardar el crontab:')) . '</p><pre><code>' . Html::escape($installCronCommand) . '</code></pre></div>'
            : '<p class="meta">' . Html::escape($this->t('admin.activity_update_help', 'Este modo procesa pequeños lotes de recepción y envíos cuando hay visitas. Es más lento, pero no requiere configurar cron.')) . '</p>';
        $updatesHtml = '<form method="post" action="?route=instance-admin/settings" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label class="check-row"><input name="update_mode" type="radio" value="activity"' . ($updateMode === 'activity' ? ' checked' : '') . '/><span>' . Html::escape($this->t('admin.update_activity', 'Actualización al detectarse actividad (lenta)')) . '</span></label>'
            . '<label class="check-row"><input name="update_mode" type="radio" value="cron"' . ($updateMode === 'cron' ? ' checked' : '') . '/><span>' . Html::escape($this->t('admin.update_cron', 'Usar cron')) . '</span></label>'
            . $updatesHelp
            . '<button type="submit">' . Html::escape($this->t('admin.save_updates', 'Guardar actualizaciones')) . '</button>'
            . '</form>';
        $settingsHtml = '<form method="post" action="?route=instance-admin/settings" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('admin.favicon', 'Favicon')) . ' <input name="favicon" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>' . Html::escape($this->t('admin.default_avatar', 'Avatar por defecto')) . ' <input name="default_avatar" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<label>' . Html::escape($this->t('admin.default_header', 'Cabecera por defecto')) . ' <input name="default_header" type="file" accept="image/png,image/jpeg,image/gif,image/webp"/></label>'
            . '<button type="submit">' . Html::escape($this->t('admin.save_images', 'Guardar imágenes')) . '</button>'
            . '</form>';
        $serversHtml = '<form method="post" action="?route=instance-admin/server-blocks" class="follow-new-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="action" value="add"/>'
            . '<label>' . Html::escape($this->t('admin.blocked_server', 'Servidor bloqueado')) . ' <input name="server" placeholder="servidor.org"/></label>'
            . '<button type="submit">' . Html::escape($this->t('actions.add', 'Añadir')) . '</button>'
            . '</form><div class="actor-list">';

        foreach ($blockedServers as $server) {
            $serversHtml .= '<article class="actor-row"><span>' . Html::escape((string)$server) . '</span>'
                . '<form method="post" action="?route=instance-admin/server-blocks" class="actor-actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="action" value="delete"/>'
                . '<input type="hidden" name="server" value="' . Html::escape((string)$server) . '"/>'
                . '<button type="submit" class="danger">' . Html::escape($this->t('actions.delete', 'Borrar')) . '</button></form></article>';
        }
        $serversHtml .= '</div>';
        $blockedUsersHtml = '<form method="post" action="?route=instance-admin/actor-block" class="follow-new-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="action" value="add"/>'
            . '<label>' . Html::escape($this->t('admin.block_actor_serverwide', 'Bloquear usuario en todo el servidor')) . ' <input name="actor_query" placeholder="@usuario@servidor.org o https://..." required/></label>'
            . '<button type="submit" class="danger">' . Html::escape($this->t('admin.block_user', 'Bloquear usuario')) . '</button>'
            . '</form>';

        $blockedUsersHtml .= '<h3>' . Html::escape($this->t('admin.blocked_by_members', 'Bloqueados por usuarios del servidor')) . '</h3>';
        $blockedUsersHtml .= $blockNotices === [] ? '<p class="muted">' . Html::escape($this->t('admin.no_member_blocks', 'Sin usuarios bloqueados por miembros.')) . '</p>' : '<div class="notifications">';
        foreach ($blockNotices as $notice) {
            $actor = (string)($notice['actor'] ?? '');
            $blockedBy = is_array($notice['blocked_by'] ?? null) ? $notice['blocked_by'] : [];
            $isBlocked = in_array($actor, $blockedActors, true);
            $blockedUsersHtml .= '<article class="notification follow-request"><div><strong>' . $this->actorLink($actor) . '</strong>'
                . '<p class="meta">' . Html::escape($this->t('admin.blocked_by', 'Bloqueado por:')) . ' ' . Html::escape(implode(', ', $blockedBy)) . '</p></div>';

            if ($isBlocked) {
                $blockedUsersHtml .= '<span class="meta">' . Html::escape($this->t('admin.blocked_serverwide', 'Bloqueado en todo el servidor')) . '</span>';
            } else {
                $blockedUsersHtml .= '<form method="post" action="?route=instance-admin/actor-block" class="actions">'
                    . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                    . '<input type="hidden" name="action" value="add"/>'
                    . '<input type="hidden" name="actor" value="' . Html::escape($actor) . '"/>'
                    . '<button type="submit">' . Html::escape($this->t('admin.block_on_server', 'Bloquear en servidor')) . '</button></form>';
            }

            $blockedUsersHtml .= '</article>';
        }
        $blockedUsersHtml .= $blockNotices === [] ? '' : '</div>';

        $blockedUsersHtml .= '<h3>' . Html::escape($this->t('admin.serverwide_blocked', 'Bloqueados en todo el servidor')) . '</h3>';
        if ($blockedActors === []) {
            $blockedUsersHtml .= '<p class="muted">' . Html::escape($this->t('admin.no_serverwide_blocks', 'Sin usuarios bloqueados en todo el servidor.')) . '</p>';
        } else {
            $blockedUsersHtml .= '<div class="actor-list">';
            foreach ($blockedActors as $actor) {
                $blockedUsersHtml .= '<article class="actor-row"><span>' . $this->actorLink((string)$actor) . '</span>'
                    . '<form method="post" action="?route=instance-admin/actor-block" class="actor-actions">'
                    . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                    . '<input type="hidden" name="actor" value="' . Html::escape((string)$actor) . '"/>'
                    . '<button type="submit" name="action" value="delete">' . Html::escape($this->t('admin.unblock', 'Quitar bloqueo')) . '</button>'
                    . '<button type="submit" name="action" value="purge" class="danger">' . Html::escape($this->t('admin.purge_content', 'Purgar contenido')) . '</button></form></article>';
            }
            $blockedUsersHtml .= '</div>';
        }
        $createUsersHtml = '<form method="post" action="?route=instance-admin/users" class="form-grid">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<input type="hidden" name="action" value="add"/>'
            . '<label>' . Html::escape($this->t('field.user', 'Usuario')) . ' <input name="uid" required/></label>'
            . '<label>' . Html::escape($this->t('field.name', 'Nombre')) . ' <input name="name"/></label>'
            . '<label>' . Html::escape($this->t('field.password', 'Clave')) . ' <input name="password" type="password" required/></label>'
            . '<label class="check-row"><input name="admin" type="checkbox" value="1"/><span>' . Html::escape($this->t('field.admin', 'Administrador')) . '</span></label>'
            . '<button type="submit">' . Html::escape($this->t('admin.create_user', 'Crear usuario')) . '</button></form>';
        $importUsersHtml = '<form method="post" action="?route=instance-admin/import-user" enctype="multipart/form-data" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('admin.xml_or_zip', 'Archivo XML o ZIP')) . ' <input name="archive" type="file" accept="application/xml,text/xml,application/zip,.xml,.zip" required/></label>'
            . '<label>' . Html::escape($this->t('admin.initial_password', 'Clave inicial')) . ' <input name="password" type="password" autocomplete="new-password"/></label>'
            . '<p class="meta">' . Html::escape($this->t('admin.initial_password_help', 'La clave inicial es obligatoria si el usuario importado todavía no existe en el nodo.')) . '</p>'
            . '<button type="submit">' . Html::escape($this->t('admin.import_user', 'Importar usuario')) . '</button>'
            . '</form>';
        $socializeHtml = '<form method="post" action="?route=instance-admin/socialize-user" class="instance-form">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('admin.user_to_socialize', 'Usuario a socializar')) . ' <input name="actor_query" placeholder="@natalia o maximalismo@maximalismo.blog" required/></label>'
            . '<p class="meta">' . Html::escape($this->t('admin.socialize_help', 'El usuario indicado pasará a estar en los seguidos de todos los usuarios locales. Si es externo se enviará un Follow federado por cada cuenta local.')) . '</p>'
            . '<button type="submit">' . Html::escape($this->t('admin.socialize_user', 'Socializar usuario')) . '</button>'
            . '</form>';
        $appHtml = $this->appBuildBox($csrf, $appBuild);
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
                : '<button type="submit" name="action" value="' . ($isAdmin ? 'unset-admin' : 'set-admin') . '">' . Html::escape($isAdmin ? $this->t('admin.unset_admin', 'Quitar admin') : $this->t('admin.set_admin', 'Hacer admin')) . '</button>';
            $deleteButton = $uid === $currentUid
                ? ''
                : '<button type="submit" name="action" value="delete" class="danger">' . Html::escape($this->t('admin.delete_user', 'Borrar usuario')) . '</button>';

            $editUsersHtml .= '<article class="actor-row"><div class="user-admin-mini">' . $avatarHtml
                . '<span><strong>' . $displayName . '</strong><small>@' . Html::escape($uid) . ($isAdmin ? ' · admin' : '') . '</small></span></div>'
                . '<form method="post" action="?route=instance-admin/users" class="actor-actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="uid" value="' . Html::escape($uid) . '"/>'
                . '<input name="password" type="password" placeholder="' . Html::escape($this->t('admin.new_password', 'Nueva clave')) . '"/>'
                . '<button type="submit" name="action" value="password">' . Html::escape($this->t('field.password', 'Clave')) . '</button>'
                . $adminButton
                . $deleteButton
                . '</form></article>';
        }
        $editUsersHtml .= '</div>';

        return $this->renderer->page($this->t('admin.panel_title', 'Panel de administración'), $messageHtml . $errorHtml
            . $this->panelBox($this->t('admin.instance', 'Instancia'), $instanceHtml)
            . $this->panelBox($this->t('admin.instance_images', 'Imágenes de instancia'), $settingsHtml)
            . $this->panelBox($this->t('admin.updates', 'Actualizaciones'), $updatesHtml, $openBox === 'updates')
            . $this->panelBox($this->t('admin.blocked_users', 'Usuarios bloqueados'), $blockedUsersHtml)
            . $this->panelBox($this->t('admin.blocked_servers', 'Servidores bloqueados'), $serversHtml)
            . $this->panelBox($this->t('admin.create_users', 'Crear usuarios'), $createUsersHtml)
            . $this->panelBox($this->t('admin.edit_users', 'Editar usuarios'), $editUsersHtml)
            . $this->panelBox($this->t('admin.import_user', 'Importar usuario'), $importUsersHtml)
            . $this->panelBox($this->t('admin.socialize_user', 'Socializar usuario'), $socializeHtml)
            . $this->panelBox($this->t('admin.compile_app', 'Compilar app'), $appHtml, $openBox === 'app'));
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
        string $timelineSearchResults = '',
        ?array $appDownload = null,
        array $languages = []
    ): string
    {
        $csrf = Html::escape($this->auth->csrfToken());
        $messageHtml = $message !== null ? '<p class="notice">' . Html::escape($message) . '</p>' : '';
        $errorHtml = $error !== null ? '<p class="error">' . Html::escape($error) . '</p>' : '';
        $profileHtml = $this->profileForm($uid, $profile ?? [], $csrf, $languages);
        $notificationsHtml = $this->notifications($notifications, $pendingFollows, $pendingCreates, $csrf);
        $socialHtml = $this->socialColumns($uid, $csrf, $followers, $following, $socialStates);
        $privateHtml = $this->privateMessages($privateMessages, $csrf);
        $migrationHtml = $this->migrationTools($uid, $csrf);
        $appDownloadHtml = $this->appDownloadBox($appDownload);
        $profileLink = $profileUrl !== ''
            ? '<a class="button-link secondary" href="' . Html::escape($profileUrl) . '">' . Html::escape($this->t('profile.view', 'Ver perfil')) . '</a>'
            : '';

        $logout = '<form method="post" action="?route=admin/logout" class="logout-form panel-logout">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<button type="submit">' . Html::escape($this->t('actions.logout', 'Salir')) . '</button>'
            . '</form>';

        $focus = $_GET['focus'] ?? '';
        $focus = is_string($focus) ? $focus : '';

        return $this->renderer->page($this->t('panel.user_title', 'Panel de usuario'), $messageHtml . $errorHtml
            . $this->panelBox($this->t('profile.title', 'Perfil'), '<div class="admin-actions">' . $profileLink . '</div>' . $profileHtml)
            . $this->panelBox($this->t('panel.search_timeline', 'Buscar en timeline'), $this->timelineSearch($timelineSearchQuery, $timelineSearchResults), $timelineSearchQuery !== '')
            . $this->panelBox($this->t('panel.notifications', 'Notificaciones'), $notificationsHtml, $focus === 'notifications', 'notifications')
            . $this->panelBox($this->t('panel.private_messages', 'Mensajes privados'), $privateHtml)
            . $this->panelBox($this->t('panel.network', 'Red'), $socialHtml)
            . $this->panelBox($this->t('panel.export_migrate', 'Exportar / Migrar'), $migrationHtml)
            . ($appDownloadHtml !== '' ? $this->panelBox($this->t('panel.download_app', 'Descargar app'), $appDownloadHtml) : '')
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
            . '<label>' . Html::escape($this->t('actions.search', 'Buscar')) . ' <input name="timeline_q" value="' . Html::escape($query) . '" placeholder="' . Html::escape($this->t('panel.search_placeholder', 'Texto del mensaje')) . '"/></label>'
            . '<button type="submit">' . Html::escape($this->t('actions.search', 'Buscar')) . '</button>'
            . '</form>'
            . ($query !== '' ? '<div class="timeline-search-results">' . ($results !== '' ? $results : '<p class="muted">' . Html::escape($this->t('panel.no_results', 'Sin resultados.')) . '</p>') . '</div>' : '');
    }

    private function migrationTools(string $uid, string $csrf): string
    {
        return '<div class="migration-tools">'
            . '<a class="button-link" href="?route=admin/export-user">' . Html::escape($this->t('panel.download_zip', 'Descargar ZIP')) . '</a>'
            . '<form method="post" action="?route=admin/delete-content" class="danger-zone">'
            . '<input type="hidden" name="csrf" value="' . Html::escape($csrf) . '"/>'
            . '<label>' . Html::escape($this->t('panel.confirm_delete_content', 'Confirmar borrado de contenido')) . ' <input name="confirm" placeholder="' . Html::escape($uid) . '"/></label>'
            . '<button type="submit" class="danger">' . Html::escape($this->t('panel.delete_all_content', 'Borrar todo mi contenido')) . '</button>'
            . '</form>'
            . '<form method="post" action="?route=admin/delete-account" class="danger-zone">'
            . '<input type="hidden" name="csrf" value="' . Html::escape($csrf) . '"/>'
            . '<label>' . Html::escape($this->t('panel.confirm_delete_account', 'Confirmar baja de usuario')) . ' <input name="confirm" placeholder="' . Html::escape($uid) . '"/></label>'
            . '<button type="submit" class="danger">' . Html::escape($this->t('panel.delete_account', 'Dar de baja mi usuario')) . '</button>'
            . '</form>'
            . '</div>';
    }

    private function appBuildBox(string $csrf, array $status): string
    {
        $missing = is_array($status['missing'] ?? null) ? $status['missing'] : [];
        $manifest = is_array($status['manifest'] ?? null) ? $status['manifest'] : null;
        $html = '<div class="app-build-box">';

        if ($missing !== []) {
            $html .= '<p class="error">' . Html::escape($this->t('app.missing_dependencies', 'Faltan dependencias para compilar en este servidor.')) . '</p><ul class="plain-list">';
            foreach ($missing as $item) {
                $html .= '<li>' . Html::escape((string)$item) . '</li>';
            }
            $html .= '</ul><p class="meta">' . Html::escape($this->t('app.missing_dependencies_help', 'Cuando estén instaladas, vuelve a esta caja para compilar el APK.')) . '</p>';
        } else {
            $html .= '<p class="meta">' . Html::escape($this->t('app.compile_help', 'La app se compilará con el nombre de la instancia y usará el favicon como icono.')) . '</p>'
                . '<form method="post" action="?route=instance-admin/compile-app" class="admin-actions">'
                . '<input type="hidden" name="csrf" value="' . Html::escape($csrf) . '"/>'
                . '<button type="submit">' . Html::escape($this->t('app.compile_apk', 'Compilar APK')) . '</button>'
                . '</form>';
        }

        if ($manifest !== null) {
            $url = Html::escape((string)($manifest['url'] ?? ''));
            $name = Html::escape((string)($manifest['app_name'] ?? 'App'));
            $date = Html::escape((string)($manifest['built_at'] ?? ''));
            $html .= '<p><a class="button-link" href="' . $url . '">' . Html::escape($this->t('app.download_latest_apk', 'Descargar último APK')) . '</a></p>'
                . '<p class="meta">' . $name . ($date !== '' ? ' · ' . $date : '') . '</p>';
        }

        return $html . '</div>';
    }

    private function appDownloadBox(?array $manifest): string
    {
        if ($manifest === null) {
            return '';
        }

        $url = is_string($manifest['url'] ?? null) ? $manifest['url'] : '';
        if ($url === '') {
            return '';
        }

        $name = Html::escape((string)($manifest['app_name'] ?? 'Uanna'));
        return '<p><a class="button-link" href="' . Html::escape($url) . '">' . Html::escape($this->t('panel.download_app', 'Descargar app')) . '</a></p>'
            . '<p class="meta">' . Html::escape($this->t('app.installable_android', 'App instalable en Android de {name}.', ['name' => $name])) . '</p>';
    }

    private function profileForm(string $uid, array $profile, string $csrf, array $languages): string
    {
        $name = Html::escape((string)($profile['name'] ?? $uid));
        $bio = Html::escape((string)($profile['bio'] ?? ''));
        $email = Html::escape((string)($profile['email'] ?? ''));
        $lang = Html::escape((string)($profile['lang'] ?? ''));
        $tz = Html::escape((string)($profile['tz'] ?? ''));
        $approve = (bool)($profile['approve_followers'] ?? true) ? ' checked' : '';
        $avatarPreview = Html::escape((string)($profile['avatar'] ?? ''));
        $headerPreview = Html::escape((string)($profile['header'] ?? ''));
        $languageField = '<label class="profile-language-field">' . Html::escape($this->t('field.interface_language', 'Idioma del interfaz')) . ' '
            . $this->languageSelect('lang', $lang, $languages)
            . '</label>';

        return '<form method="post" action="?route=admin/profile" enctype="multipart/form-data">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<div class="form-grid">'
            . '<label>' . Html::escape($this->t('field.name', 'Nombre')) . ' <input name="name" value="' . $name . '" autocomplete="name"/></label>'
            . '<label>' . Html::escape($this->t('field.email', 'Email')) . ' <input name="email" type="email" value="' . $email . '" autocomplete="email"/><span class="field-help">' . Html::escape($this->t('profile.email_visibility', 'Sólo lo verán las personas a las que sigas y que no estén bloqueadas por ti ni por la instancia.')) . '</span></label>'
            . '<label>' . Html::escape($this->t('field.timezone', 'Zona horaria')) . ' <input name="tz" value="' . $tz . '"/></label>'
            . '</div>'
            . $languageField
            . '<div class="image-upload-grid">'
            . $this->imageCropper('avatar', $this->t('field.avatar', 'Avatar'), $avatarPreview, '1')
            . $this->imageCropper('header', $this->t('field.header', 'Cabecera'), $headerPreview, '3')
            . '</div>'
            . '<label>' . Html::escape($this->t('field.bio', 'Bio')) . ' <textarea name="bio" rows="5">' . $bio . '</textarea></label>'
            . '<label class="check-row"><input name="approve_followers" type="checkbox" value="1"' . $approve . '/><span>' . Html::escape($this->t('profile.approve_followers', 'Aprobar seguidores manualmente')) . '</span></label>'
            . '<section class="password-change">'
            . '<h3>' . Html::escape($this->t('profile.change_password', 'Cambiar contraseña')) . '</h3>'
            . '<div class="form-grid">'
            . '<label>' . Html::escape($this->t('profile.current_password', 'Contraseña actual')) . ' <input name="current_password" type="password" autocomplete="current-password"/></label>'
            . '<label>' . Html::escape($this->t('profile.new_password', 'Nueva contraseña')) . ' <input name="new_password" type="password" autocomplete="new-password"/></label>'
            . '<label>' . Html::escape($this->t('profile.repeat_new_password', 'Repetir nueva contraseña')) . ' <input name="new_password_confirm" type="password" autocomplete="new-password"/></label>'
            . '</div>'
            . '</section>'
            . '<button type="submit">' . Html::escape($this->t('profile.save', 'Guardar perfil')) . '</button>'
            . '</form>';
    }

    private function languageSelect(string $name, string $selected, array $languages): string
    {
        if ($languages === []) {
            $languages = ['es' => 'Español'];
        }

        $selected = $selected !== '' ? $selected : (array_key_first($languages) ?? 'es');
        $html = '<select name="' . Html::escape($name) . '">';

        foreach ($languages as $code => $label) {
            $code = (string)$code;
            $html .= '<option value="' . Html::escape($code) . '"' . ($code === $selected ? ' selected' : '') . '>'
                . Html::escape((string)$label) . ' (' . Html::escape($code) . ')</option>';
        }

        return $html . '</select>';
    }

    private function imageCropper(string $field, string $label, string $preview, string $aspect): string
    {
        $image = $preview !== '' ? '<img src="' . $preview . '" alt=""/>' : '<span>' . Html::escape($this->t('image.no_image', 'Sin imagen')) . '</span>';

        return '<section class="image-cropper" data-field="' . Html::escape($field) . '" data-aspect="' . Html::escape($aspect) . '">'
            . '<h3>' . Html::escape($label) . '</h3>'
            . '<div class="crop-preview">' . $image . '<canvas hidden></canvas></div>'
            . '<input type="hidden" name="' . Html::escape($field) . '_image"/>'
            . '<label>' . Html::escape($this->t('image.upload', 'Subir imagen')) . ' <input name="' . Html::escape($field) . '_upload" type="file" accept="image/png,image/jpeg,image/webp"/></label>'
            . '<label>' . Html::escape($this->t('image.zoom', 'Zoom')) . ' <input class="crop-zoom" type="range" min="1" max="3" step="0.01" value="1"/></label>'
            . '<label>' . Html::escape($this->t('image.horizontal', 'Horizontal')) . ' <input class="crop-x" type="range" min="-1" max="1" step="0.01" value="0"/></label>'
            . '<label>' . Html::escape($this->t('image.vertical', 'Vertical')) . ' <input class="crop-y" type="range" min="-1" max="1" step="0.01" value="0"/></label>'
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
                . '<div><strong>' . Html::escape($this->t('notification.new_follower', 'Nuevo seguidor')) . '</strong><p class="meta">' . $actorHtml . '</p></div>'
                . '<form method="post" action="?route=admin/moderation/follow" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">' . Html::escape($this->t('actions.approve', 'Aprobar')) . '</button>'
                . '<button name="decision" value="reject" type="submit">' . Html::escape($this->t('actions.discard', 'Descartar')) . '</button>'
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
                ? '<p class="meta">' . Html::escape($this->t('notification.in_reply_to', 'En respuesta a')) . ' <a href="' . Html::escape($replyTo) . '">' . Html::escape($replyTo) . '</a></p>'
                : '';

            $html .= '<article class="notification follow-request">'
                . '<div><strong>' . Html::escape($this->t('notification.pending_post', 'Publicación pendiente')) . '</strong><p class="meta">' . $actorHtml . ($objectHtml !== '' ? ' ' . Html::escape($this->t('notification.published', 'publicó')) . ' ' . $objectHtml : '') . '</p>'
                . $replyHtml
                . '</div>'
                . '<form method="post" action="?route=admin/moderation/create" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">' . Html::escape($this->t('actions.approve', 'Aprobar')) . '</button>'
                . '<button name="decision" value="reject" type="submit">' . Html::escape($this->t('actions.discard', 'Descartar')) . '</button>'
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
                . '<strong>' . Html::escape((string)($notification['label'] ?? $this->t('notification.title', 'Notificación'))) . '</strong>'
                . $body
                . '<p class="meta"><time datetime="' . Html::escape($date) . '">' . Html::escape(DateFormat::human($date)) . '</time></p>'
                . '</article>';
        }

        return $html !== '' ? '<div class="notifications">' . $html . '</div>' : '<p class="muted">' . Html::escape($this->t('notification.empty', 'Sin notificaciones recientes.')) . '</p>';
    }

    private function notificationBody(string $type, string $actor, string $objid): string
    {
        if (in_array($type, ['Like', 'Announce'], true) && $actor !== '' && $objid !== '') {
            $verb = $type === 'Like' ? $this->t('notification.favorited', 'favoriteó') : $this->t('notification.boosted', 'impulsó');
            return '<p class="meta">' . $this->actorLink($actor) . ' ' . $verb . ' <a href="' . Html::escape($objid) . '">' . Html::escape($objid) . '</a></p>';
        }

        if ($type === 'Follow' && $actor !== '') {
            return '<p class="meta">' . $this->actorLink($actor) . '</p>';
        }

        if ($type === 'Create' && $actor !== '' && $objid !== '') {
            return '<p class="meta">' . $this->actorLink($actor) . ' ' . Html::escape($this->t('notification.replied_in', 'respondió en')) . ' <a href="' . Html::escape($objid) . '">' . Html::escape($objid) . '</a></p>';
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
            . '<label>' . Html::escape($this->t('network.follow_new_user', 'Seguir nuevo usuario')) . ' <input name="actor_query" placeholder="@usuario@servidor.org o https://..." required/></label>'
            . '<button type="submit">' . Html::escape($this->t('actions.follow', 'Seguir')) . '</button>'
            . '</form>'
            . '<div class="social-columns">'
            . '<section><h3>' . Html::escape($this->t('network.followers', 'Seguidores')) . ' <span class="social-count">' . count($followers) . '</span></h3>' . $this->actorList($uid, $csrf, $followers, $socialStates, false) . '</section>'
            . '<section><h3>' . Html::escape($this->t('network.following', 'Seguidos')) . ' <span class="social-count">' . count($following) . '</span></h3>' . $this->actorList($uid, $csrf, $following, $socialStates, true) . '</section>'
            . '</div>';
    }

    private function actorList(string $uid, string $csrf, array $actors, array $socialStates, bool $showUnfollow): string
    {
        if ($actors === []) {
            return '<p class="muted">' . Html::escape($this->t('network.empty', 'Sin registros.')) . '</p>';
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
            $html .= '<button type="submit" name="action" value="follow">' . Html::escape($this->t('actions.follow', 'Seguir')) . '</button>';
        } else {
            $html .= '<button type="submit" name="action" value="' . ($isMuted ? 'unmute' : 'mute') . '">'
                . Html::escape($isMuted ? $this->t('actions.unmute', 'Quitar silencio') : $this->t('actions.mute', 'Silenciar'))
                . '</button>';
            if ($showUnfollow) {
                $html .= '<button type="submit" name="action" value="unfollow">' . Html::escape($this->t('actions.unfollow', 'No seguir')) . '</button>';
            }
        }

        $html .= '<button type="submit" name="action" value="' . ($isBlocked ? 'unblock' : 'block') . '" class="danger">'
            . Html::escape($isBlocked ? $this->t('actions.unblock', 'Desbloquear') : $this->t('actions.block', 'Bloquear'))
            . '</button>'
            . '</form>';

        return $html;
    }

    private function privateMessages(array $messages, string $csrf): string
    {
        $html = '<form method="post" action="?route=admin/private-message" class="private-compose">'
            . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
            . '<label>' . Html::escape($this->t('private.recipient', 'Destinatario')) . ' <input name="to" type="text" placeholder="@usuario, @usuario@servidor.org o https://..." required/></label>'
            . '<label>' . Html::escape($this->t('private.message', 'Mensaje')) . ' <textarea name="content" rows="5" required></textarea></label>'
            . '<button type="submit">' . Html::escape($this->t('private.send', 'Enviar privado')) . '</button>'
            . '</form>';

        if ($messages === []) {
            return $html . '<p class="muted">' . Html::escape($this->t('private.empty', 'Sin mensajes privados recientes.')) . '</p>';
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
            'label' => $this->t('private.unknown_sender', 'Remitente desconocido'),
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
            return '<p class="muted">' . Html::escape($this->t('moderation.no_pending_follows', 'No hay solicitudes de seguimiento pendientes.')) . '</p>';
        }

        $html = '';

        foreach ($pendingFollows as $case) {
            $record = is_array($case['record'] ?? null) ? $case['record'] : [];
            $activity = is_array($record['activity'] ?? null) ? $record['activity'] : [];
            $actor = $activity['actor'] ?? $record['actor'] ?? 'actor desconocido';
            $caseId = (string)($case['case_id'] ?? '');

            $html .= '<article class="review">'
                . '<p><strong>' . Html::escape($this->t('moderation.follow_request', 'Solicitud Follow')) . '</strong></p>'
                . '<p class="meta">' . Html::escape(is_string($actor) ? $actor : 'actor desconocido') . '</p>'
                . '<form method="post" action="?route=admin/moderation/follow" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">' . Html::escape($this->t('actions.approve', 'Aprobar')) . '</button>'
                . '<button name="decision" value="reject" type="submit">' . Html::escape($this->t('actions.reject', 'Rechazar')) . '</button>'
                . '</form>'
                . '</article>';
        }

        return $html;
    }

    private function createReviews(array $pendingCreates, string $csrf): string
    {
        if ($pendingCreates === []) {
            return '<p class="muted">' . Html::escape($this->t('moderation.no_pending_remote_posts', 'No hay publicaciones remotas pendientes.')) . '</p>';
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
                . ($content !== '' ? '<div class="content">' . $content . '</div>' : '<p class="muted">' . Html::escape($this->t('moderation.no_visible_content', 'Sin contenido visible.')) . '</p>')
                . '<form method="post" action="?route=admin/moderation/create" class="actions">'
                . '<input type="hidden" name="csrf" value="' . $csrf . '"/>'
                . '<input type="hidden" name="case" value="' . Html::escape($caseId) . '"/>'
                . '<button name="decision" value="approve" type="submit">' . Html::escape($this->t('actions.approve', 'Aprobar')) . '</button>'
                . '<button name="decision" value="reject" type="submit">' . Html::escape($this->t('actions.reject', 'Rechazar')) . '</button>'
                . '</form>'
                . '</article>';
        }

        return $html;
    }
}
