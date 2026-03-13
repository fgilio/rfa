<?php

use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// -- scopeKey --

test('scopeKey uses project_id when provided', function () {
    $key = ReviewSession::scopeKey('/tmp/repo', 42, 'fp-abc');

    expect($key)->toBe([
        'project_id' => 42,
        'context_fingerprint' => 'fp-abc',
    ]);
});

test('scopeKey uses repo_path when project_id is null', function () {
    $key = ReviewSession::scopeKey('/tmp/repo', null, 'fp-abc');

    expect($key)->toBe([
        'repo_path' => '/tmp/repo',
        'context_fingerprint' => 'fp-abc',
    ]);
});

// -- casts --

test('casts viewed_files as array', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $session = ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/test',
        'viewed_files' => ['file1.txt', 'file2.txt'],
        'comments' => [],
    ]);

    $fresh = $session->fresh();

    expect($fresh->viewed_files)->toBe(['file1.txt', 'file2.txt'])
        ->and($fresh->viewed_files)->toBeArray();
});

test('casts comments as array', function () {
    $project = Project::create([
        'slug' => 'test-project',
        'name' => 'Test',
        'path' => '/tmp/test',
        'git_common_dir' => '/tmp/test/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $comments = [['body' => 'looks good', 'line' => 10]];

    $session = ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/test',
        'viewed_files' => [],
        'comments' => $comments,
    ]);

    $fresh = $session->fresh();

    expect($fresh->comments)->toBe($comments)
        ->and($fresh->comments)->toBeArray();
});
