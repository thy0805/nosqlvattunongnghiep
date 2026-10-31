<?php
$public=dirname(__DIR__).'/api-laravel/public';
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path!=='/'&&is_file($public.$path))return false;
require_once dirname(__DIR__).'/api-laravel/vendor/autoload.php';
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) return;
    $file = dirname(__DIR__).'/api-laravel/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
    if (is_file($file)) require $file;
}, true, true);
require $public.'/index.php';
