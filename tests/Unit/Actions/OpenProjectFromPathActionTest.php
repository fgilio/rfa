<?php

use App\Actions\OpenProjectFromPathAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_open_path_');
    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/file.txt', "ok\n");
    $this->commitTestRepo($this->repoPath, 'init');
});

test('returns the registered project for a real git directory', function () {
    $project = app(OpenProjectFromPathAction::class)->handle($this->repoPath);

    expect($project)->toBeInstanceOf(Project::class)
        ->and($project->path)->toBe(realpath($this->repoPath));
});

test('returns null when path does not exist', function () {
    $missing = sys_get_temp_dir().'/rfa_definitely_not_here_'.uniqid();

    expect(app(OpenProjectFromPathAction::class)->handle($missing))->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('path_not_found');
});

test('returns null when path is a file rather than a directory', function () {
    $file = $this->repoPath.'/file.txt';

    expect(app(OpenProjectFromPathAction::class)->handle($file))->toBeNull();
});

test('returns null and swallows the exception when registration fails', function () {
    $nonGit = $this->createTempDirectory('rfa_open_path_nongit_');

    expect(app(OpenProjectFromPathAction::class)->handle($nonGit))->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('not_a_git_repository');

    expect(Project::count())->toBe(0);
});

test('records an owner error without a duplicate warning when registration fails unexpectedly', function () {
    Schema::drop('projects');
    Log::spy();

    expect(app(OpenProjectFromPathAction::class)->handle($this->repoPath))->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('project_registration_failed')
        ->and(Context::get('rfa.error_class'))->not->toBeNull();

    Log::shouldNotHaveReceived('warning');
});
