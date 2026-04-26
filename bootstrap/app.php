<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Outside web middleware so SQLite session contention can't false-flip
        // the recovery overlay. Stock `/up` would also pull a CDN font.
        then: function (): void {
            Route::get('/_rfa/health', fn () => response('ok', 200, ['Content-Type' => 'text/plain']))
                ->name('rfa.health');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
