<?php

use App\Actions\SessionStateAction;
use App\Models\ReviewSession;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('creates session row when none exists', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    $globalComment = $this->faker->sentence();

    app(SessionStateAction::class)->saveGlobalNote($repoPath, $globalComment);

    $session = ReviewSession::where('repo_path', $repoPath)->first();

    expect($session)->not->toBeNull();
    expect($session->global_comment)->toBe($globalComment);
});

test('updates existing session row', function () {
    $repoPath = '/tmp/'.$this->faker->word();
    ReviewSession::create(['repo_path' => $repoPath, 'global_comment' => '']);

    app(SessionStateAction::class)->saveGlobalNote($repoPath, 'updated-global');

    $session = ReviewSession::where('repo_path', $repoPath)->first();
    expect(ReviewSession::where('repo_path', $repoPath)->count())->toBe(1);
    expect($session->global_comment)->toBe('updated-global');
});

test('keys by project_id when provided', function () {
    $project = $this->createTestProject([
        'slug' => 'test-proj',
        'path' => '/tmp/test-proj',
    ]);

    app(SessionStateAction::class)->saveGlobalNote('/tmp/test-proj', 'hello', $project->id);

    $session = ReviewSession::where('project_id', $project->id)->first();

    expect($session)->not->toBeNull();
    expect($session->global_comment)->toBe('hello');
});
