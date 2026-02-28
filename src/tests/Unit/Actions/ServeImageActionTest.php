<?php

use App\Actions\ServeImageAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_serve_image_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    // Init git repo
    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false',
    ]));

    // Create a binary PNG-like file and commit it
    File::put($this->tmpDir.'/logo.png', "PNG\0original");
    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git add -A',
        "git commit -m 'initial'",
    ]));

    $this->project = Project::create([
        'slug' => 'test-img',
        'name' => 'Test Image Project',
        'path' => $this->tmpDir,
        'git_common_dir' => $this->tmpDir.'/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns content and mime type for working ref', function () {
    File::put($this->tmpDir.'/icon.png', "PNG\0working");

    $result = app(ServeImageAction::class)->handle($this->project->id, 'icon.png', 'working');

    expect($result)->not->toBeNull()
        ->and($result['content'])->toBe("PNG\0working")
        ->and($result['mimeType'])->toBe('image/png');
});

test('returns content and mime type for head ref', function () {
    // logo.png was committed in beforeEach with "PNG\0original"
    File::put($this->tmpDir.'/logo.png', "PNG\0modified");

    $result = app(ServeImageAction::class)->handle($this->project->id, 'logo.png', 'head');

    expect($result)->not->toBeNull()
        ->and($result['content'])->toBe("PNG\0original")
        ->and($result['mimeType'])->toBe('image/png');
});

test('returns null when file not found', function () {
    $result = app(ServeImageAction::class)->handle($this->project->id, 'missing.png', 'working');

    expect($result)->toBeNull();
});

test('returns null when file not in HEAD', function () {
    File::put($this->tmpDir.'/untracked.png', "PNG\0new");

    $result = app(ServeImageAction::class)->handle($this->project->id, 'untracked.png', 'head');

    expect($result)->toBeNull();
});

test('detects correct mime type for each extension', function () {
    $extensions = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'bmp' => 'image/bmp',
        'avif' => 'image/avif',
    ];

    foreach ($extensions as $ext => $expectedMime) {
        File::put($this->tmpDir."/test.{$ext}", "binary\0data");

        $result = app(ServeImageAction::class)->handle($this->project->id, "test.{$ext}", 'working');

        expect($result['mimeType'])->toBe($expectedMime, "Expected {$expectedMime} for .{$ext}");
    }
});
