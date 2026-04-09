<?php

use App\Actions\EnsureRfaGitExcludeAction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->action = app(EnsureRfaGitExcludeAction::class);
    $this->tempDir = $this->createTempDirectory('rfa_git_exclude_');
    $this->initTestRepo($this->tempDir);
});

test('creates exclude entry when file does not exist', function () {
    $excludePath = $this->tempDir.'/.git/info/exclude';

    // Remove default exclude file if git init created one
    if (File::exists($excludePath)) {
        File::delete($excludePath);
    }

    $this->action->handle($this->tempDir);

    expect(File::exists($excludePath))->toBeTrue()
        ->and(File::get($excludePath))->toContain('.rfa/');
});

test('creates info directory if missing', function () {
    $infoDir = $this->tempDir.'/.git/info';

    if (File::isDirectory($infoDir)) {
        File::deleteDirectory($infoDir);
    }

    $this->action->handle($this->tempDir);

    expect(File::isDirectory($infoDir))->toBeTrue()
        ->and(File::get($infoDir.'/exclude'))->toContain('.rfa/');
});

test('appends to existing exclude file', function () {
    $excludePath = $this->tempDir.'/.git/info/exclude';
    File::ensureDirectoryExists(dirname($excludePath));
    File::put($excludePath, "*.log\n");

    $this->action->handle($this->tempDir);

    $contents = File::get($excludePath);
    expect($contents)->toContain("*.log\n")
        ->and($contents)->toContain('.rfa/');
});

test('is idempotent', function () {
    $this->action->handle($this->tempDir);
    $this->action->handle($this->tempDir);

    $excludePath = $this->tempDir.'/.git/info/exclude';
    $contents = File::get($excludePath);
    $count = substr_count($contents, '.rfa/');

    expect($count)->toBe(1);
});

test('detects .rfa without trailing slash as already excluded', function () {
    $excludePath = $this->tempDir.'/.git/info/exclude';
    File::ensureDirectoryExists(dirname($excludePath));
    File::put($excludePath, ".rfa\n");

    $this->action->handle($this->tempDir);

    $contents = File::get($excludePath);
    expect(substr_count($contents, '.rfa'))->toBe(1);
});

test('handles file without trailing newline', function () {
    $excludePath = $this->tempDir.'/.git/info/exclude';
    File::ensureDirectoryExists(dirname($excludePath));
    File::put($excludePath, '*.log');

    $this->action->handle($this->tempDir);

    $contents = File::get($excludePath);
    expect($contents)->toBe("*.log\n.rfa/\n");
});
