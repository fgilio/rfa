<?php

use App\Actions\OpenPathForReviewAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_open_target_repo_');
    $this->workspacePath = $this->createTempDirectory('rfa_file_workspace_parent_').'/Files';
    $this->externalPath = $this->createTempDirectory('rfa_open_target_external_').'/notes.md';

    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/README.md', "seed\n");
    $this->commitTestRepo($this->repoPath, 'initial');
    File::put($this->externalPath, "# Notes\n");

    config(['rfa.file_workspace_path' => $this->workspacePath]);

    $this->action = app(OpenPathForReviewAction::class);
});

test('opens a repository directory without a focused file', function () {
    $target = $this->action->handle($this->repoPath);

    expect($target)->not->toBeNull()
        ->and($target['project']->path)->toBe(realpath($this->repoPath))
        ->and($target['filePath'])->toBeNull();
});

test('registers the containing repository and resolves a file relative to its root', function () {
    File::ensureDirectoryExists($this->repoPath.'/reports');
    File::put($this->repoPath.'/reports/audit.md', "# Audit\n");

    $target = $this->action->handle($this->repoPath.'/reports/audit.md');

    expect($target)->not->toBeNull()
        ->and($target['project']->path)->toBe(realpath($this->repoPath))
        ->and($target['filePath'])->toBe('reports/audit.md')
        ->and(Project::count())->toBe(1);
});

test('preserves a repository symlink instead of opening its outside target', function () {
    $outside = dirname($this->externalPath).'/outside.md';
    File::put($outside, "outside\n");
    symlink($outside, $this->repoPath.'/linked.md');

    $target = $this->action->handle($this->repoPath.'/linked.md');

    expect($target)->not->toBeNull()
        ->and($target['project']->path)->toBe(realpath($this->repoPath))
        ->and($target['filePath'])->toBe('linked.md')
        ->and($target['project']->external_paths)->toBeNull();
});

test('opens a dangling repository symlink by its lexical path', function () {
    symlink('missing.md', $this->repoPath.'/dangling.md');

    $target = $this->action->handle($this->repoPath.'/dangling.md');

    expect($target)->not->toBeNull()
        ->and($target['project']->path)->toBe(realpath($this->repoPath))
        ->and($target['filePath'])->toBe('dangling.md');
});

test('links a file outside Git to the managed Files workspace', function () {
    $target = $this->action->handle($this->externalPath);

    expect($target)->not->toBeNull()
        ->and($target['project']->name)->toBe('Files')
        ->and($target['project']->path)->toBe(realpath($this->workspacePath))
        ->and($target['filePath'])->toBe('external/notes.md')
        ->and($target['project']->fresh()->external_paths)->toBe([
            ['label' => 'notes.md', 'path' => realpath($this->externalPath)],
        ])
        ->and(File::isDirectory($this->workspacePath.'/.git'))->toBeTrue()
        ->and(Context::get('rfa.reason'))->toBeNull();
});

test('reuses the managed workspace and existing external link', function () {
    $first = $this->action->handle($this->externalPath);
    $second = $this->action->handle($this->externalPath);

    expect($second['project']->is($first['project']))->toBeTrue()
        ->and($second['filePath'])->toBe($first['filePath'])
        ->and($second['project']->fresh()->external_paths)->toHaveCount(1)
        ->and(Project::count())->toBe(1);
});

test('rejects a missing path without creating the managed workspace', function () {
    $target = $this->action->handle(dirname($this->externalPath).'/missing.md');

    expect($target)->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('path_not_found')
        ->and(File::exists($this->workspacePath))->toBeFalse()
        ->and(Project::count())->toBe(0);
});
