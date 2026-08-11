<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (is_string($path)) {
    $file = __DIR__ . $path;

    if ($path !== '/' && is_file($file)) {
        return false;
    }

    if ($path === '/uanna.png') {
        $logo = dirname(__DIR__, 2) . '/uanna.png';

        if (is_file($logo)) {
            header('Content-Type: image/png');
            header('Content-Length: ' . (string)filesize($logo));
            readfile($logo);
            return true;
        }
    }

    if ($path === '/favicon.ico') {
        require dirname(__DIR__) . '/src/Oannes/Autoload.php';
        Oannes\Autoload::register();
        $config = require dirname(__DIR__) . '/config/oannes.php';
        $settings = new Oannes\InstanceSettings(new Oannes\FileStore($config['data_dir']), $config);
        $favicon = $settings->faviconPath();
        $file = __DIR__ . '/' . ltrim($favicon, '/');

        if (!is_file($file)) {
            $file = dirname(__DIR__, 2) . '/uanna.png';
        }

        if (is_file($file)) {
            header('Content-Type: ' . (mime_content_type($file) ?: 'image/png'));
            header('Content-Length: ' . (string)filesize($file));
            readfile($file);
            return true;
        }
    }

    if (preg_match('#^/([a-zA-Z0-9_-]{1,64})/s/([^/]+)$#', $path, $match)) {
        $static = dirname(__DIR__, 2) . '/user/' . $match[1] . '/static/' . $match[2];

        if (is_file($static)) {
            $type = mime_content_type($static) ?: 'application/octet-stream';
            header('Content-Type: ' . $type);
            header('Content-Length: ' . (string)filesize($static));
            readfile($static);
            return true;
        }
    }
}

require __DIR__ . '/index.php';
