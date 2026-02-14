<?php

use App\Actions\ResolveRepoPathAction;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

test('returns ENV variable when set', function () {
    $path = '/tmp/rfa_test_'.uniqid();
    $_ENV['RFA_REPO_PATH'] = $path;

    $result = app(ResolveRepoPathAction::class)->handle();

    expect($result)->toBe($path);

    unset($_ENV['RFA_REPO_PATH']);
});

test('reads .rfa_repo_path file when ENV not set', function () {
    unset($_ENV['RFA_REPO_PATH']);

    $path = '/tmp/rfa_test_'.uniqid();
    $file = base_path('.rfa_repo_path');
    File::put($file, "  {$path}  ");

    $result = app(ResolveRepoPathAction::class)->handle();

    expect($result)->toBe($path);

    File::delete($file);
});

test('falls back to getcwd when no ENV or file', function () {
    unset($_ENV['RFA_REPO_PATH']);

    $file = base_path('.rfa_repo_path');
    if (File::exists($file)) {
        File::delete($file);
    }

    $result = app(ResolveRepoPathAction::class)->handle();

    expect($result)->toBe(getcwd());
});
