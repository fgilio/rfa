<?php

use App\Actions\ResolveProjectEntryUrlAction;
use App\DTOs\DiffTarget;
use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, InteractsWithTestRepositories::class);

afterEach(function () {
    $this->cleanupTrackedTempDirectories();
});

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_entry_url_test_');
    $this->initTestRepo($this->repoPath);

    File::put($this->repoPath.'/file.txt', "line\n");
    $this->commitTestRepo($this->repoPath, 'first');
    $this->firstSha = trim($this->runTestRepoCommand($this->repoPath, 'git rev-parse HEAD'));

    File::put($this->repoPath.'/file.txt', "line\nline2\n");
    $this->commitTestRepo($this->repoPath, 'second');
    $this->secondSha = trim($this->runTestRepoCommand($this->repoPath, 'git rev-parse HEAD'));

    $this->project = Project::factory()->create([
        'slug' => 'entry-test',
        'path' => $this->repoPath,
        'branch' => 'main',
        'default_base_branch' => 'main',
    ]);

    $this->action = app(ResolveProjectEntryUrlAction::class);
});

// -- empty / fallback cases --

test('returns review-page when project does not exist', function () {
    expect($this->action->handle('does-not-exist'))
        ->toBe(route('review-page', ['slug' => 'does-not-exist']));
});

test('returns review-page for a project with no saved selection', function () {
    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

test('uses the supplied fallback mode when no row exists', function () {
    expect($this->action->handle($this->project->slug, LastViewMode::Context))
        ->toBe(route('context-page', ['slug' => 'entry-test']));
});

// -- mode dimension --

test('routes to context-page when last_view_mode is Context', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Context,
    ]);

    expect($this->action->handle($this->project->slug, LastViewMode::Review))
        ->toBe(route('context-page', ['slug' => 'entry-test']));
});

test('saved Review mode beats fallback Context', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::WorkingTree,
    ]);

    expect($this->action->handle($this->project->slug, LastViewMode::Context))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

// -- review kinds --

test('builds the working-tree URL for WorkingTree kind', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::WorkingTree,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

test('builds the commit URL when the saved sha still resolves', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Commit,
        'last_view_to' => $this->secondSha,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page.commit', ['slug' => 'entry-test', 'hash' => $this->secondSha]));
});

test('falls back to working tree when the saved commit no longer resolves', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Commit,
        'last_view_to' => str_repeat('0', 40),
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

test('builds the range URL via the catchall format', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Range,
        'last_view_from' => $this->firstSha,
        'last_view_to' => $this->secondSha,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test', 'ref' => $this->secondSha, 'baseRef' => $this->firstSha]));
});

test('preserves a parent-suffix on the range from', function () {
    $fromWithSuffix = $this->firstSha.'^';

    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::Range,
        'last_view_from' => $fromWithSuffix,
        'last_view_to' => $this->secondSha,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test', 'ref' => $this->secondSha, 'baseRef' => $fromWithSuffix]));
});

test('builds the range-to-working URL', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::RangeToWorking,
        'last_view_from' => $this->firstSha,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page.range-to-working', ['slug' => 'entry-test', 'rangeFromWorking' => $this->firstSha]));
});

test('falls back to working tree when range-to-working from no longer resolves', function () {
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::RangeToWorking,
        'last_view_from' => str_repeat('0', 40),
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

test('rebuilds the entire-repo URL from the empty tree without a ref check', function () {
    // The empty tree always exists but is a tree, not a commit, so resolveRef
    // (which forces ^{commit}) returns null for it. Restore must special-case it
    // rather than fall through to the working tree.
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::RangeToWorking,
        'last_view_from' => DiffTarget::EMPTY_TREE_HASH,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page.range-to-working', [
            'slug' => 'entry-test',
            'rangeFromWorking' => DiffTarget::EMPTY_TREE_HASH,
        ]));
});

// -- since_base re-resolution --

test('since_base re-resolves against the current merge-base', function () {
    // Branch off main, advance feature.
    $this->runTestRepoCommand($this->repoPath, 'git checkout -b feature');
    File::put($this->repoPath.'/feature.txt', "f\n");
    $this->commitTestRepo($this->repoPath, 'feature commit');

    $this->project->update(['branch' => 'feature']);

    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::SinceBase,
        // Stale from-value: re-resolution should ignore it and recompute.
        'last_view_from' => str_repeat('0', 40),
    ]);

    $expected = route('review-page.range-to-working', [
        'slug' => 'entry-test',
        'rangeFromWorking' => $this->secondSha,
    ]);

    expect($this->action->handle($this->project->slug))->toBe($expected);
});

test('since_base falls back to working tree when base is not configured', function () {
    $this->project->update(['default_base_branch' => null]);

    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::SinceBase,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});

test('since_base falls back to working tree when on the base branch itself', function () {
    // Project still on `main`, base is `main` — no commits ahead of itself.
    ReviewSession::create([
        'project_id' => $this->project->id,
        'repo_path' => $this->repoPath,
        'last_view_mode' => LastViewMode::Review,
        'last_view_kind' => LastViewKind::SinceBase,
    ]);

    expect($this->action->handle($this->project->slug))
        ->toBe(route('review-page', ['slug' => 'entry-test']));
});
