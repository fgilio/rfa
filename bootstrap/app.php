<?php

use App\Actions\EnforceLocalLogChannelsAction;
use App\Actions\RehydrateNativeRuntimeConfigAction;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Point the (version-cached) config at THIS launch's native API port and IPC
// secret before ANY provider registers. NativePHP's package provider runs
// before app providers and, during registration, constructs a long-lived
// Client (EventWatcher) from config('nativephp-internal.*') — so a version-
// cached config must be corrected here, not in a provider, or that retained
// client would post broadcasts to the previous launch's port/secret. Config is
// already loaded by the time RegisterProviders runs; the action no-ops unless
// the config is cached (a packaged build). See RehydrateNativeRuntimeConfigAction.
$app->beforeBootstrapping(RegisterProviders::class, function (): void {
    (new RehydrateNativeRuntimeConfigAction)->handle();
});

// RFA keeps logs on the user's machine, so the off-box stock channels come out
// of the resolved configuration before any provider can resolve one.
$app->beforeBootstrapping(RegisterProviders::class, function (): void {
    (new EnforceLocalLogChannelsAction)->handle();
});

return $app;
