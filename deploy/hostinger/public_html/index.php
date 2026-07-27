<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
|--------------------------------------------------------------------------
| Hostinger split-root front controller
|--------------------------------------------------------------------------
|
| public_html is the web root. The Laravel application itself must be placed
| next to it, outside the browser-accessible directory:
|
| /home/u715639661/domains/task.avant.od.ua/public_html
| /home/u715639661/domains/task.avant.od.ua/tasktracker_app
|
| If you use a different folder name, update the path below.
|
*/

$appBasePath = realpath(__DIR__.'/../tasktracker_app');

if ($appBasePath === false) {
    http_response_code(500);
    echo 'Application path is not configured.';
    exit;
}

if (file_exists($maintenance = $appBasePath.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $appBasePath.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $appBasePath.'/bootstrap/app.php';

$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
