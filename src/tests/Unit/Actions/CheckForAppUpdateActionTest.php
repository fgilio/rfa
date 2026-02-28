<?php

use App\Actions\CheckForAppUpdateAction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_app_update_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false', // Disable GPG signing so test commits work without a key
    ]));

    File::put($this->tmpDir.'/file.txt', "ok\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');

    config(['rfa.github_repo' => 'test-owner/test-repo']);
    Cache::flush();
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('detects update when remote is ahead', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['ahead_by' => 3], 200),
    ]);

    $result = (new CheckForAppUpdateAction)->handle($this->tmpDir);

    expect($result)
        ->available->toBeTrue()
        ->count->toBe(3);
});

test('reports no update when up to date', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['ahead_by' => 0], 200),
    ]);

    $result = (new CheckForAppUpdateAction)->handle($this->tmpDir);

    expect($result)
        ->available->toBeFalse()
        ->count->toBe(0);
});

test('handles API failure gracefully', function () {
    Http::fake([
        'api.github.com/*' => Http::response(null, 500),
    ]);

    $result = (new CheckForAppUpdateAction)->handle($this->tmpDir);

    expect($result)
        ->available->toBeFalse()
        ->count->toBe(0);
});

test('handles rate limit gracefully', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'rate limit exceeded'], 403),
    ]);

    $result = (new CheckForAppUpdateAction)->handle($this->tmpDir);

    expect($result)
        ->available->toBeFalse()
        ->count->toBe(0);
});

test('caches result by local SHA', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['ahead_by' => 2], 200),
    ]);

    $action = new CheckForAppUpdateAction;
    $action->handle($this->tmpDir);
    $action->handle($this->tmpDir);

    Http::assertSentCount(1);
});

test('cache invalidates after SHA changes', function () {
    Http::fake([
        'api.github.com/*' => Http::response(['ahead_by' => 1], 200),
    ]);

    $action = new CheckForAppUpdateAction;
    $action->handle($this->tmpDir);

    // Create a new commit to change the SHA
    File::put($this->tmpDir.'/file.txt', "changed\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m second');

    $action->handle($this->tmpDir);

    Http::assertSentCount(2);
});
