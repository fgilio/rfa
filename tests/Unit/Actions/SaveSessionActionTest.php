<?php

use App\Actions\SaveSessionAction;
use App\Models\Project;
use App\Models\ReviewSession;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('creates session when none exists', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    $comments = [['id' => 'c-1', 'file' => 'f.php', 'body' => $this->faker->sentence()]];
    $reviewedFiles = ['f.php' => 'hash-f'];
    $globalComment = $this->faker->sentence();

    app(SaveSessionAction::class)->handle($repoPath, $comments, $reviewedFiles, $globalComment);

    $session = ReviewSession::where('repo_path', $repoPath)->first();

    expect($session)->not->toBeNull();
    expect($session->comments)->toBe($comments);
    expect($session->viewed_files)->toBe($reviewedFiles);
    expect($session->global_comment)->toBe($globalComment);
});

test('updates existing session', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    ReviewSession::create(['repo_path' => $repoPath, 'comments' => [], 'viewed_files' => [], 'global_comment' => '']);

    $newComments = [['id' => 'c-2', 'file' => 'a.php', 'body' => 'updated']];
    $reviewedFiles = ['a.php' => 'hash-a'];

    app(SaveSessionAction::class)->handle($repoPath, $newComments, $reviewedFiles, 'global');

    $session = ReviewSession::where('repo_path', $repoPath)->first();
    expect(ReviewSession::where('repo_path', $repoPath)->count())->toBe(1);
    expect($session->comments)->toBe($newComments);
    expect($session->viewed_files)->toBe($reviewedFiles);
});

test('keys by project_id when provided', function () {
    $project = Project::create([
        'slug' => 'test-proj',
        'name' => 'test-proj',
        'path' => '/tmp/test-proj',
        'git_common_dir' => '/tmp/test-proj/.git',
        'is_worktree' => false,
    ]);

    $comments = [['id' => 'c-1', 'file' => 'f.php', 'body' => 'hello']];

    app(SaveSessionAction::class)->handle('/tmp/test-proj', $comments, [], '', $project->id);

    $session = ReviewSession::where('project_id', $project->id)->first();

    expect($session)->not->toBeNull();
    expect($session->comments)->toBe($comments);
});
