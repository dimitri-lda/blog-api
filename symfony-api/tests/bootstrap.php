<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    $root = dirname(__DIR__);
    $path = is_file($root.'/.env') ? $root.'/.env' : $root.'/.env.example';
    (new Dotenv())->bootEnv($path);
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
