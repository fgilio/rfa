<?php

use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('casts is_worktree as boolean', function () {
    $project = Project::create([
        'slug' => 'bool-test',
        'name' => 'Bool Test',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => 1,
        'branch' => 'main',
    ]);

    $fresh = $project->fresh();

    expect($fresh->is_worktree)->toBeTrue()
        ->and($fresh->is_worktree)->toBeBool();
});

test('casts respect_global_gitignore as boolean', function () {
    $project = Project::create([
        'slug' => 'gitignore-test',
        'name' => 'Gitignore Test',
        'path' => '/tmp/test2',
        'git_common_dir' => '/tmp/test2/.git',
        'is_worktree' => false,
        'branch' => 'main',
        'respect_global_gitignore' => 1,
    ]);

    $fresh = $project->fresh();

    expect($fresh->respect_global_gitignore)->toBeTrue()
        ->and($fresh->respect_global_gitignore)->toBeBool();
});

test('enforces unique slug constraint', function () {
    Project::create([
        'slug' => 'unique-slug',
        'name' => 'Project A',
        'path' => '/tmp/a',
        'git_common_dir' => '/tmp/a/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    expect(fn () => Project::create([
        'slug' => 'unique-slug',
        'name' => 'Project B',
        'path' => '/tmp/b',
        'git_common_dir' => '/tmp/b/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]))->toThrow(QueryException::class);
});
