<?php

/**
 * Post-sleep 419 recovery contract. These three pieces work together:
 * - long SESSION_LIFETIME so the session rarely expires
 * - keepalive poll so it never expires while the Mac is awake
 * - interceptor so any 419 that slips through reloads silently
 *
 * If any of the three regresses, 419s leak back to the user as
 * Livewire's "page has expired" modal.
 */
test('.env.example sets a session lifetime of at least 1 week', function () {
    $env = file_get_contents(dirname(__DIR__, 2).'/.env.example');

    expect($env)->toMatch('/^SESSION_LIFETIME=(\d+)/m');

    preg_match('/^SESSION_LIFETIME=(\d+)/m', $env, $matches);

    $weekInMinutes = 60 * 24 * 7;
    expect((int) $matches[1])->toBeGreaterThanOrEqual($weekInMinutes);
});

test('app layout loads session-recovery.js', function () {
    $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/layouts/app.blade.php');

    expect($layout)->toContain('/js/session-recovery.js');
});

test('session-recovery.js intercepts 419 responses and reloads', function () {
    $script = file_get_contents(dirname(__DIR__, 2).'/public/js/session-recovery.js');

    expect($script)
        ->toContain('livewire:init')
        ->toContain('Livewire.interceptRequest')
        ->toContain('status !== 419')
        ->toContain('preventDefault')
        ->toContain('window.location.reload');
});

test('session-recovery.js guards against a post-reload 419 loop', function () {
    $script = file_get_contents(dirname(__DIR__, 2).'/public/js/session-recovery.js');

    expect($script)
        ->toContain('sessionStorage')
        ->toContain('__rfa419RecoveryAt');
});
