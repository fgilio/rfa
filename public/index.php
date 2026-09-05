<?php

use App\Support\ContentLength;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$request = Request::capture();
$kernel = $app->make(Kernel::class);

// The kernel has dispatched RequestHandled by now, so Livewire and Flux have
// injected their assets and the content is final.
$response = $kernel->handle($request);
ContentLength::declare($response);
$response->send();

$kernel->terminate($request, $response);
