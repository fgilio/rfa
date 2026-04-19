<?php

use App\Models\Comment;
use App\Models\Project;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('forProjectOrRepo limits the result to a given project_id', function () {
    $project = Project::create([
        'slug' => 'p', 'name' => 'p', 'path' => '/tmp/p',
        'git_common_dir' => '/tmp/p/.git', 'is_worktree' => false,
    ]);

    Comment::create([
        'id' => 'c-in', 'project_id' => $project->id, 'repo_path' => $project->path,
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'in',
    ]);
    Comment::create([
        'id' => 'c-out', 'project_id' => null, 'repo_path' => '/tmp/other',
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'out',
    ]);

    $ids = Comment::query()->forProjectOrRepo($project->id, $project->path)->pluck('id')->all();

    expect($ids)->toBe(['c-in']);
});

test('forProjectOrRepo falls back to repo_path when no project_id is given', function () {
    Comment::create([
        'id' => 'c-a', 'project_id' => null, 'repo_path' => '/tmp/a',
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'a',
    ]);
    Comment::create([
        'id' => 'c-b', 'project_id' => null, 'repo_path' => '/tmp/b',
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'b',
    ]);

    $ids = Comment::query()->forProjectOrRepo(null, '/tmp/a')->pluck('id')->all();

    expect($ids)->toBe(['c-a']);
});

test('forProjectOrRepo with null project_id excludes rows that have a project_id set', function () {
    $project = Project::create([
        'slug' => 'p', 'name' => 'p', 'path' => '/tmp/p',
        'git_common_dir' => '/tmp/p/.git', 'is_worktree' => false,
    ]);

    Comment::create([
        'id' => 'c-with-project', 'project_id' => $project->id, 'repo_path' => '/tmp/p',
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'x',
    ]);
    Comment::create([
        'id' => 'c-bare', 'project_id' => null, 'repo_path' => '/tmp/p',
        'origin_ref' => 'working', 'file_path' => 'f.php', 'side' => 'right', 'body' => 'y',
    ]);

    $ids = Comment::query()->forProjectOrRepo(null, '/tmp/p')->pluck('id')->all();

    expect($ids)->toBe(['c-bare']);
});
