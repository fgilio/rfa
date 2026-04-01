<?php

use App\Actions\ResolveProjectAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('returns project array for valid slug', function () {
    $project = Project::create([
        'slug' => 'my-project',
        'name' => 'my-project',
        'path' => '/tmp/my-project',
        'git_common_dir' => '/tmp/my-project/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $result = app(ResolveProjectAction::class)->handle('my-project');

    expect($result)->toBeArray();
    expect($result['id'])->toBe($project->id);
    expect($result['slug'])->toBe('my-project');
    expect($result['path'])->toBe('/tmp/my-project');
});

test('returns null for unknown slug', function () {
    $result = app(ResolveProjectAction::class)->handle('nonexistent');

    expect($result)->toBeNull();
});
