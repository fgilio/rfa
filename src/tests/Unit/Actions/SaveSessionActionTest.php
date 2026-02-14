<?php

use App\Actions\SaveSessionAction;
use App\Models\ReviewSession;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('creates session when none exists', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    $comments = [['id' => 'c-1', 'file' => 'f.php', 'body' => $this->faker->sentence()]];
    $viewedFiles = ['f.php'];
    $globalComment = $this->faker->sentence();

    app(SaveSessionAction::class)->handle($repoPath, $comments, $viewedFiles, $globalComment);

    $session = ReviewSession::where('repo_path', $repoPath)->first();

    expect($session)->not->toBeNull();
    expect($session->comments)->toBe($comments);
    expect($session->viewed_files)->toBe($viewedFiles);
    expect($session->global_comment)->toBe($globalComment);
});

test('updates existing session', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    ReviewSession::create(['repo_path' => $repoPath, 'comments' => [], 'viewed_files' => [], 'global_comment' => '']);

    $newComments = [['id' => 'c-2', 'file' => 'a.php', 'body' => 'updated']];

    app(SaveSessionAction::class)->handle($repoPath, $newComments, ['a.php'], 'global');

    expect(ReviewSession::where('repo_path', $repoPath)->count())->toBe(1);
    expect(ReviewSession::where('repo_path', $repoPath)->first()->comments)->toBe($newComments);
});
