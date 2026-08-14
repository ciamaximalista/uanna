![Uanna](uanna-banner.png)

# Uanna

Uanna is a microblogging server for invitation-based communities in the Fediverse. It is designed for groups under 80 people that prefer a simple, auditable, easy-to-maintain tool over a large platform with many dependencies.

Uanna works with JSON and XML files. It does not use SQLite, MySQL, MariaDB, Redis, or any other database engine.

## Features

- Local note publishing with public, followers-only, or private visibility.
- Personal timeline for authenticated users.
- Public home page with an editable instance presentation.
- Public profiles at routes such as `/@user`.
- Internal profiles for cached federated actors, while keeping a link to the original profile.
- Favorites and boosts, with avatars linked to profiles and reversible actions.
- Reply trees based on `inReplyTo`, without confusing links with replies.
- `@user` and `@user@server` mentions with links and notifications.
- Multiple image attachments with alternative text, shown in separate modals to keep timelines focused on reading and people, and to reduce scroll stress.
- Editing and deleting your own posts, sending the corresponding ActivityPub activities.
- User panel with profile, notifications, followers, following, search, and private messages.
- Multilingual interface based on JSON files, with an instance default language and a language chosen by each user.
- User export from the panel as a ZIP file, including `archive.xml` and local attachments.
- Notifications with unread and pending counters, links to original profiles, and affected posts.
- Administration panel for configuring images, instance name, presentation, users, updates, and blocks.
- Android app build from the administration panel, using the instance name and favicon.
- User import from Uanna XML or ZIP, restoring profile, posts, and attachments.
- Moderation for signups, follows, pending posts, actor blocks, and server blocks.
- Inbox and delivery processing by cron or on detected activity, without overloading normal visits.
- Import and diagnostic tools for migrations from snac.
- Atomic file storage to reduce corruption after power loss or failures.

## License

Uanna is distributed under the European Union Public Licence, version 1.2, EUPL-1.2. The full text is in `LICENSE.txt`.

## Requirements

- PHP 8.1 or later.
- A web server with PHP support, for example Apache with PHP-FPM, Nginx with PHP-FPM, or PHP's built-in server for testing.
- PHP extensions: `json`, `openssl`, `mbstring`, `fileinfo`, `gd`, `dom`, and `zip`.
- A domain with valid HTTPS for proper federation.
- Write permissions for PHP in `oannes/data` and `oannes/public/assets`.
- Optional for building the Android app from the panel: JDK 17, Gradle, and an Android SDK with compatible platform/build-tools.

## Installation

Clone the repository:

```sh
git clone https://github.com/ciamaximalista/uanna.git
cd uanna
```

Create the local configuration:

```sh
cp oannes/config/oannes.example.php oannes/config/oannes.php
```

Edit `oannes/config/oannes.php` and change at least:

```php
'host' => 'your-domain.org',
'base_url' => 'https://your-domain.org',
'timezone' => 'Europe/Madrid',
```

Create the data and asset directories, and grant write permissions to the web server user. Uanna writes from PHP, so the operating owner should be the real Apache/PHP-FPM user (`www-data`, `apache`, `nginx`, `nobody`, or another user depending on the server). If you will also run tasks from the console, add your user to the same group.

```sh
cd /path/to/uanna

# Change these values to the real PHP user/group on your server.
WEB_USER=www-data
WEB_GROUP=www-data

sudo install -d -m 2775 -o "$WEB_USER" -g "$WEB_GROUP" oannes/data oannes/public/assets
sudo chown -R "$WEB_USER:$WEB_GROUP" oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +

# Optional, only if you will run maintenance commands from your shell user.
sudo usermod -aG "$WEB_GROUP" "$USER"
```

Log out and log back in if you changed groups with `usermod`.

To detect the PHP user, inspect the non-root web processes:

```sh
ps -eo user,comm,args | grep -E 'apache2|httpd|php-fpm|nginx' | grep -v root
```

On Debian/Ubuntu with Apache and PHP-FPM this is usually `www-data:www-data`; on some shared Apache installations it may be `nobody:nogroup`; on CentOS/RHEL it may be `apache:apache`; on Nginx with PHP-FPM it depends on the pool's configured `user`.

Do not mix data created by incompatible users without a shared writable group. If some files are owned by `david` and others by `nobody`, notifications, replies, attachments, or queues may fail even if the site appears to load correctly.

Configure the web server so the document root points to:

```text
oannes/public
```

All routes should end in `oannes/public/index.php` except existing static files. In Apache you can use a rule equivalent to:

```apache
DocumentRoot /path/to/uanna/oannes/public

<Directory /path/to/uanna/oannes/public>
    AllowOverride All
    Require all granted
    FallbackResource /index.php
</Directory>
```

In Nginx, a minimal PHP-FPM configuration would be:

```nginx
server {
    server_name your-domain.org;
    root /path/to/uanna/oannes/public;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Open the site in a browser. If no local user exists yet, Uanna will show the first administrator registration page. That user can create and edit users, configure the instance, and manage blocks.

## Queue Updates

Inbox and ActivityPub delivery processing are configured from the administration panel, in the `Updates` box.

There are two modes:

- `Update when activity is detected (slow)`: Uanna processes inbox and deliveries opportunistically during normal web visits. Each request may run a small inbox and delivery batch, with concurrency locking and cooldowns to avoid overloading the server. It does not require cron, but it can take longer if the instance has little web activity.
- `Use cron`: Uanna does not process queues during visits. The administrator must schedule the queue commands in the crontab of the same operating user that runs PHP, normally `www-data` on Debian/Ubuntu. This is the recommended mode for instances with more federated traffic, or to keep work moving even when nobody visits the site for hours.

Limits are adjusted in `oannes/config/oannes.php`:

```php
'opportunistic_workers_enabled' => true,
'opportunistic_workers_cooldown_seconds' => 15,
'opportunistic_inbox_limit' => 5,
'opportunistic_delivery_limit' => 2,
```

If you choose `Use cron`, the panel calculates the installation path and shows the exact commands for that instance. The base commands are:

```sh
php oannes/bin/oannes.php queue-run 25
php oannes/bin/oannes.php inbox-run 25
```

It also shows the recommended `crontab` block, replacing `/path/to/uanna` with the real path. The default recommendation is to run inbox and deliveries every minute:

```cron
* * * * * cd /path/to/uanna && php oannes/bin/oannes.php queue-run 25 >/dev/null 2>&1
* * * * * cd /path/to/uanna && php oannes/bin/oannes.php inbox-run 25 >/dev/null 2>&1
```

Install those lines in the web/PHP user's crontab, not in root's crontab and not with `sudo` inside the cron command. On Debian/Ubuntu this is usually:

```sh
sudo crontab -u www-data -e
```

To inspect where the tasks are installed:

```sh
sudo crontab -u www-data -l
sudo crontab -l
crontab -l
```

If queue jobs run as `root` while the web writes as `www-data`, files in `oannes/data` may be left with incompatible ownership and later operations can fail. The cron user and the PHP user should be the same whenever possible.

## Android App

The repository includes a lightweight Android project in `android/`. The app opens the instance in a full-screen WebView, keeps the session, allows file uploads, and shows local notifications while it is open when the panel counter increases.

From `Administration panel > Build app`, the administrator can generate an APK for the instance:

- The app name is taken from the instance name.
- The icon is generated from the instance favicon.
- The URL loaded by the app is taken from `base_url`.
- The resulting APK is published at `oannes/public/assets/instance/uanna-app.apk`.
- When a compiled APK exists, all users see the `Download app` box in their panel.

If dependencies are missing, the `Build app` box explains what must be installed. Building on the server requires:

- JDK 17.
- Gradle.
- Android SDK with platform `android-35` and build-tools.

On Debian/Ubuntu, the JDK can be installed with:

```sh
sudo apt install openjdk-17-jdk
```

Gradle and the Android SDK can be installed with Android Studio, with Android command line tools, or by placing a local toolchain in:

```text
android/.toolchain/jdk
android/.toolchain/gradle/current
android/.toolchain/sdk
```

The toolchain, builds, APKs, keys, and `local.properties` are not part of the repository.

## Useful Commands

```sh
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
php oannes/bin/oannes.php queue-list
php oannes/bin/oannes.php auth-audit
php oannes/bin/oannes.php readiness 20
php oannes/bin/oannes.php backfill-boosts 50
```

## User Export and Import

Each user can download a migration ZIP file from `Panel > Export / Migrate`. The package contains:

```text
user-uanna.zip
archive.xml
media/
```

`archive.xml` includes the profile and all local posts by the user. If those posts have images or document attachments stored in the instance, Uanna copies them into `media/` and rewrites their URLs as relative paths.

Posts can include several image attachments. The default limit is four images per post and can be changed with `max_attachment_count` in `oannes/config/oannes.php`; `max_attachment_bytes` applies to each image.

From `Panel > Export / Migrate`, the user can also delete all their content or close their account, entering their username as confirmation.

The administrator can import users from `Administration panel > Import user`. Import accepts:

- Plain XML generated by Uanna.
- Full ZIP generated by Uanna, with `archive.xml` and attachments.

When importing a ZIP, Uanna restores attachments into the new instance user's static directory, rewrites their public URLs, and rebuilds indexes. If the user does not exist yet, the administrator must provide an initial password.

For migrations from snac:

```sh
php oannes/bin/oannes.php analyse-snac /path/to/snac
php oannes/bin/oannes.php import-snac /path/to/snac
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
```

### Actor Identity When Migrating From snac

New Uanna installations use actors under `/u/user`:

```php
'local_actor_path' => '/u',
'legacy_actor_paths' => [],
```

If you migrate a snac instance that already federated with root-level actors, for example `https://domain.org/david`, it is advisable to preserve that identity as the primary one. Otherwise, other servers may still follow the old actor and fail to incorporate new posts correctly even if they receive the deliveries.

In that case, configure the instance like this before publishing new content:

```php
'local_actor_path' => '',
'legacy_actor_paths' => [
    '/u/%s',
],
```

With this configuration, the main actor remains `https://domain.org/user` and the `/u/user` route works as a compatible alias. Use this option only for migrations that need to preserve old identities; for new installations, keeping `/u/user` is better.

## Permission Policy

Uanna reads and writes JSON files, XML files, and attachments directly on disk. It does not use a database, so system permissions are part of the installation.

The recommended rule is:

- The user running PHP must be able to read and write `oannes/data` and `oannes/public/assets`.
- All those directories should have setgid (`2775`) so new files inherit the correct group.
- Files should be `0664` and directories `2775`.
- Sessions in `oannes/data/sessions` must be writable by the same group; Uanna tries to create them as `0660`.
- If commands are run from the console, the shell user must belong to the same group as PHP.
- Incompatible owners such as `david` and `nobody` should not be mixed if the group does not have write access.

To repair an existing installation:

```sh
cd /path/to/uanna

WEB_USER=www-data
WEB_GROUP=www-data

sudo chown -R "$WEB_USER:$WEB_GROUP" oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +
sudo usermod -aG "$WEB_GROUP" "$USER"
```

After `usermod`, log out and log back in so the group applies to your shell.

If PHP is actually running as `nobody`, the concrete repair would be:

```sh
cd /path/to/uanna

sudo chown -R nobody:nogroup oannes/data oannes/public/assets
sudo find oannes/data oannes/public/assets -type d -exec chmod 2775 {} +
sudo find oannes/data oannes/public/assets -type f -exec chmod 0664 {} +
sudo usermod -aG nogroup david
```

If the server uses `www-data`, replace `nobody:nogroup` with `www-data:www-data`. The important point is that PHP and console tasks work on the same writable group.

## Data and Backups

The live data of an instance is stored in `oannes/data` and is not part of the repository. Attachments and user static files live in `oannes/data/media/<user>`. Back up `oannes/data` and `oannes/public/assets`.

Never publish:

- `oannes/data`
- `oannes/public/assets`
- `oannes/config/oannes.php`
- migration backups
- local keys, sessions, queues, or indexes from a real community

## Local Development

To test without configuring Apache or Nginx:

```sh
php -S 127.0.0.1:8000 -t oannes/public oannes/public/server.php
```

Then open:

```text
http://127.0.0.1:8000
```

In local development, real federation requires HTTPS and a domain reachable by other servers.
