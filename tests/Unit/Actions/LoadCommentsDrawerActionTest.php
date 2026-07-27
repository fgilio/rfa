<?php

use App\Actions\LoadCommentsDrawerAction;
use App\Models\Comment;
use App\Models\CommentReply;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::factory()->create(['path' => '/tmp/drawer']);
    $this->comment = Comment::factory()->for($this->project)->create([
        'id' => 'c-drawer',
        'repo_path' => '/tmp/drawer',
        'file_path' => 'app/Foo.php',
        'body' => 'Root body',
    ]);
    CommentReply::factory()->for($this->comment)->agent('codex-cli', 'Codex')->create([
        'body' => 'Reply needle',
    ]);
});

test('filters root threads by reply body and author identity', function (string $filter) {
    $result = app(LoadCommentsDrawerAction::class)->handle(
        '/tmp/drawer',
        $this->project->id,
        filter: $filter,
    );

    expect($result['totalCount'])->toBe(1)
        ->and($result['groupedComments']['app/Foo.php'][0]['id'])->toBe('c-drawer')
        ->and($result['groupedComments']['app/Foo.php'][0]['isReplyFilterMatch'])->toBeTrue()
        ->and($result['groupedComments']['app/Foo.php'][0]['replies'])->toHaveCount(1);
})->with(['needle', 'codex-cli', 'Codex']);

test('does not expand replies when only the root matches the filter', function () {
    $result = app(LoadCommentsDrawerAction::class)->handle(
        '/tmp/drawer',
        $this->project->id,
        filter: 'Root body',
    );

    expect($result['groupedComments']['app/Foo.php'][0]['isReplyFilterMatch'])->toBeFalse();
});

test('keeps the pool count unfiltered and skips row loading while closed', function () {
    $result = app(LoadCommentsDrawerAction::class)->handle(
        '/tmp/drawer',
        $this->project->id,
        filter: 'missing',
        includeRows: false,
    );

    expect($result)->toBe([
        'groupedComments' => [],
        'totalCount' => 1,
    ]);
});

test('loads any number of reply threads with a constant query count', function () {
    Comment::factory()
        ->count(5)
        ->for($this->project)
        ->create(['repo_path' => '/tmp/drawer'])
        ->each(fn (Comment $comment) => CommentReply::factory()->count(3)->for($comment)->create());

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    app(LoadCommentsDrawerAction::class)->handle('/tmp/drawer', $this->project->id);

    expect($queries)->toBeLessThanOrEqual(3);
});

test('grouped reply predicates cannot leak submitted or foreign project roots', function () {
    $otherProject = Project::factory()->create(['path' => '/tmp/other']);
    $foreign = Comment::factory()->for($otherProject)->create([
        'repo_path' => '/tmp/other',
        'body' => 'Foreign',
    ]);
    $submitted = Comment::factory()->for($this->project)->create([
        'repo_path' => '/tmp/drawer',
        'body' => 'Submitted',
        'submitted_at' => now(),
    ]);
    CommentReply::factory()->for($foreign)->create(['body' => 'leak needle']);
    CommentReply::factory()->for($submitted)->create(['body' => 'leak needle']);

    $result = app(LoadCommentsDrawerAction::class)->handle(
        '/tmp/drawer',
        $this->project->id,
        filter: 'leak needle',
    );

    expect($result['groupedComments'])->toBe([]);
});
