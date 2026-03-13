<?php

use App\Actions\ListProjectsAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('returns empty array when no projects exist', function () {
    $result = app(ListProjectsAction::class)->handle();

    expect($result)->toBe([]);
});

test('returns projects grouped by git_common_dir', function () {
    Project::create([
        'slug' => 'proj-a',
        'name' => 'Project A',
        'path' => '/tmp/a',
        'git_common_dir' => '/shared/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);
    Project::create([
        'slug' => 'proj-b',
        'name' => 'Project B',
        'path' => '/tmp/b',
        'git_common_dir' => '/shared/.git',
        'is_worktree' => true,
        'branch' => 'feature',
    ]);
    Project::create([
        'slug' => 'proj-c',
        'name' => 'Project C',
        'path' => '/tmp/c',
        'git_common_dir' => '/other/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $result = app(ListProjectsAction::class)->handle();

    expect($result)->toHaveCount(2)
        ->and($result['/shared/.git'])->toHaveCount(2)
        ->and($result['/other/.git'])->toHaveCount(1);
});

test('sorts by recent activity by default', function () {
    $old = Project::create([
        'slug' => 'old-proj',
        'name' => 'Old Project',
        'path' => '/tmp/old',
        'git_common_dir' => '/old/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);
    // Force updated_at via query to bypass Eloquent auto-timestamps
    Project::where('id', $old->id)->update(['updated_at' => now()->subDays(10)]);

    $new = Project::create([
        'slug' => 'new-proj',
        'name' => 'New Project',
        'path' => '/tmp/new',
        'git_common_dir' => '/new/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $result = app(ListProjectsAction::class)->handle('recent');
    $keys = array_keys($result);

    expect($keys[0])->toBe('/new/.git');
});

test('sorts alphabetically when sortBy is alpha', function () {
    Project::create([
        'slug' => 'proj-z',
        'name' => 'Zeta',
        'path' => '/tmp/z',
        'git_common_dir' => '/z/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);
    Project::create([
        'slug' => 'proj-a',
        'name' => 'Alpha',
        'path' => '/tmp/a',
        'git_common_dir' => '/a/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $result = app(ListProjectsAction::class)->handle('alpha');

    $firstGroup = reset($result);
    expect($firstGroup[0]['name'])->toBe('Alpha');
});

test('calculates comment count from review sessions', function () {
    $project = Project::create([
        'slug' => 'comment-proj',
        'name' => 'Comment Project',
        'path' => '/tmp/comments',
        'git_common_dir' => '/comments/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/comments',
        'context_fingerprint' => 'fp-1',
        'comments' => [['body' => 'a'], ['body' => 'b']],
        'viewed_files' => [],
    ]);
    ReviewSession::create([
        'project_id' => $project->id,
        'repo_path' => '/tmp/comments',
        'context_fingerprint' => 'fp-2',
        'comments' => [['body' => 'c']],
        'viewed_files' => [],
    ]);

    $result = app(ListProjectsAction::class)->handle();
    $group = reset($result);

    expect($group[0]['comment_count'])->toBe(3);
});

test('includes last_active_ago as human-readable string', function () {
    Project::create([
        'slug' => 'ago-proj',
        'name' => 'Ago Project',
        'path' => '/tmp/ago',
        'git_common_dir' => '/ago/.git',
        'is_worktree' => false,
        'branch' => 'main',
    ]);

    $result = app(ListProjectsAction::class)->handle();
    $group = reset($result);

    expect($group[0])->toHaveKey('last_active_ago')
        ->and($group[0]['last_active_ago'])->toBeString();
});
