<?php

use Oannes\Autoload;
use Oannes\Auth;
use Oannes\FileStore;
use Oannes\LocalUsers;
use Oannes\ObjectRepository;
use Oannes\Renderer;
use Oannes\Router;

require dirname(__DIR__) . '/src/Oannes/Autoload.php';
Autoload::register();

$config = require dirname(__DIR__) . '/config/oannes.php';
$store = new FileStore($config['data_dir']);
$repo = new ObjectRepository($store);
$renderer = new Renderer($repo, $config);
$users = new LocalUsers($store, $config);
$auth = new Auth($store);

(new Router($config, $store, $repo, $renderer, $users, $auth))->dispatch();
