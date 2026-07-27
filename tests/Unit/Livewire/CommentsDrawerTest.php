<?php

use App\Models\Comment;
use App\Models\CommentReply;
use Faker\Factory as Faker;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));

    $this->project = $this->createTestProject(['slug' => 'proj', 'path' => '/tmp/proj']);

    Comment::create([
        'id' => 'c-open-a',
        'project_id' => $this->project->id,
        'repo_path' => '/tmp/proj',
        'origin_ref' => 'working',
        'file_path' => 'src/foo.php',
        'side' => 'right',
        'body' => 'open on foo',
    ]);

    Comment::create([
        'id' => 'c-open-b',
        'project_id' => $this->project->id,
        'repo_path' => '/tmp/proj',
        'origin_ref' => 'working',
        'file_path' => 'src/bar.php',
        'side' => 'right',
        'body' => 'open on bar',
    ]);

    Comment::create([
        'id' => 'c-submitted',
        'project_id' => $this->project->id,
        'repo_path' => '/tmp/proj',
        'origin_ref' => 'abc123',
        'file_path' => 'src/foo.php',
        'side' => 'right',
        'body' => 'already shipped',
        'submitted_at' => now(),
    ]);

    Comment::create([
        'id' => 'c-other-repo',
        'project_id' => null,
        'repo_path' => '/tmp/other',
        'origin_ref' => 'working',
        'file_path' => 'src/foo.php',
        'side' => 'right',
        'body' => 'different repo',
    ]);
});

test('shows only unsubmitted comments by default, grouped by file', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle');

    $grouped = $component->get('groupedComments');

    expect(array_keys($grouped))->toEqualCanonicalizing(['src/foo.php', 'src/bar.php']);
    expect(collect($grouped)->flatten(1)->pluck('id')->all())->toEqualCanonicalizing(['c-open-a', 'c-open-b']);
});

test('totalCount reports the number of visible comments', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ]);

    expect($component->get('totalCount'))->toBe(2);
});

test('ignores comments that belong to a different repo', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle');

    $ids = collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all();

    expect($ids)->not->toContain('c-other-repo');
});

test('showSubmitted=true includes archived comments', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle')->set('showSubmitted', true);

    $ids = collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all();

    expect($ids)->toContain('c-submitted');
    expect($component->get('totalCount'))->toBe(3);
});

test('filter matches on file_path substring', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle')->set('filter', 'bar');

    $ids = collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all();

    expect($ids)->toBe(['c-open-b']);
});

test('filter matches on body substring', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle')->set('filter', 'open on bar');

    $ids = collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all();

    expect($ids)->toBe(['c-open-b']);
});

test('filter matches reply body and author without changing root count', function () {
    CommentReply::factory()
        ->for(Comment::findOrFail('c-open-a'))
        ->agent('codex-cli', 'Codex')
        ->create(['body' => 'Agent follow-up']);

    foreach (['follow-up', 'codex-cli', 'Codex'] as $filter) {
        $component = Livewire::test('comments-drawer', [
            'repoPath' => '/tmp/proj',
            'projectId' => $this->project->id,
        ])->call('toggle')->set('filter', $filter);

        expect(collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all())->toBe(['c-open-a'])
            ->and($component->get('totalCount'))->toBe(2);
    }
});

test('refreshes its thread read model on comment-thread-updated', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->call('toggle');

    CommentReply::factory()->for(Comment::findOrFail('c-open-a'))->create(['body' => 'New reply']);

    $component->dispatch('comment-thread-updated', commentId: 'c-open-a', fileId: 'file-a', replies: [])
        ->assertSee('1 reply');
});

test('groupedComments returns empty when the drawer is closed (skips the expensive query)', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ]);

    expect($component->get('open'))->toBeFalse();
    expect($component->get('groupedComments'))->toBe([]);
    // totalCount stays accurate so the header badge keeps working.
    expect($component->get('totalCount'))->toBe(2);
});

test('filter does not affect totalCount (pool size, not filtered size)', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ])->set('filter', 'no-match-at-all');

    expect($component->get('totalCount'))->toBe(2);
});

test('toggle() flips the open flag', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/proj',
        'projectId' => $this->project->id,
    ]);

    expect($component->get('open'))->toBeFalse();

    $component->call('toggle');
    expect($component->get('open'))->toBeTrue();

    $component->call('toggle');
    expect($component->get('open'))->toBeFalse();
});

test('falls back to repo_path when no project_id is given', function () {
    $component = Livewire::test('comments-drawer', [
        'repoPath' => '/tmp/other',
        'projectId' => null,
    ])->call('toggle');

    $ids = collect($component->get('groupedComments'))->flatten(1)->pluck('id')->all();

    expect($ids)->toBe(['c-other-repo']);
});
