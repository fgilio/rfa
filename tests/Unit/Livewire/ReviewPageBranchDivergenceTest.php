<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\SessionStateAction;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DiffTarget;
use App\Enums\DivergenceState;
use App\Models\Comment;
use App\Models\Project;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/**
 * Install a controllable GetCurrentHeadAction fake in the container.
 * Tests mutate `$fake->result` to simulate a HEAD move across poll ticks.
 */
function bindFakeCurrentHeadAction(CurrentHeadResult $initial): object
{
    $fake = new class($initial)
    {
        public function __construct(public CurrentHeadResult $result) {}

        public function handle(string $repoPath, ?string $targetBranch = null): CurrentHeadResult
        {
            return $this->result;
        }
    };

    app()->instance(GetCurrentHeadAction::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false],
    ];

    $this->project = Project::create([
        'slug' => 'divergence-test',
        'name' => 'Divergence Test',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => null,
        'respect_global_gitignore' => true,
    ]);

    $project = $this->project;
    $files = $this->files;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug, bool $touch = false): ?array
        {
            return $this->project->fresh()->toArray();
        }
    });

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            return $this->files;
        }
    });

    app()->bind(SessionStateAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
        }

        public function saveGlobalNote(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    $gitFileContentMock = Mockery::mock(GitFileContentService::class);
    $gitFileContentMock->shouldReceive('hashAt')->andReturn('mock-hash');
    app()->instance(GitFileContentService::class, $gitFileContentMock);

    app()->bind(BackfillGlobalGitignoreAction::class, fn () => new class
    {
        public function handle(int $projectId, string $repoPath): ?string
        {
            return null;
        }
    });
});

test('aligned state when HEAD matches projectBranch', function () {
    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    expect($component->get('divergenceContext'))->toBe([]);
});

test('silent auto-follow when HEAD diverges and no comments exist', function () {
    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'b'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    expect($component->get('projectBranch'))->toBe('feature-x');
    expect($this->project->fresh()->branch)->toBe('feature-x');
});

test('diverged banner shown when HEAD differs and a comment exists', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'c'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);
    expect($component->get('divergenceContext.target'))->toBe('main');
    expect($component->get('divergenceContext.currentBranch'))->toBe('feature-x');
    expect($component->get('divergenceContext.commentCount'))->toBe(1);
    expect($component->get('projectBranch'))->toBe('main');
    $component->assertSeeHtml('data-testid="divergence-banner-diverged"');
});

test('detached banner shown when HEAD is detached', function () {
    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: null, sha: 'd'.str_repeat('0', 39), detached: true, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::Detached);
    expect($component->get('divergenceContext.shortSha'))->toBe('d'.str_repeat('0', 6));
    $component->assertSeeHtml('data-testid="divergence-banner-detached"');
});

test('missing_target banner shown when target branch no longer exists', function () {
    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'e'.str_repeat('0', 39), detached: false, targetExists: false));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::MissingTarget);
    expect($component->get('divergenceContext.target'))->toBe('main');
    expect($component->get('divergenceContext.currentBranch'))->toBe('feature-x');
    $component->assertSeeHtml('data-testid="divergence-banner-missing"');
});

test('keepReviewing suppresses the banner for that branch, even across new commits', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    $firstSha = 'a'.str_repeat('0', 39);
    $sameBranchNewSha = 'a'.str_repeat('9', 39);
    $otherBranchSha = 'b'.str_repeat('0', 39);

    $fake = bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: $firstSha, detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);

    $component->call('keepReviewing');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    // Suppression keys on the branch identity, not the sha.
    expect($component->get('dismissedAtBranch'))->toBe('feature-x');

    // Same HEAD: stays suppressed.
    $component->call('checkHeadDivergence');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);

    // New commit on the SAME branch (sha changes, branch unchanged): still suppressed.
    $fake->result = new CurrentHeadResult(branch: 'feature-x', sha: $sameBranchNewSha, detached: false, targetExists: true);
    $component->call('checkHeadDivergence');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);

    // HEAD moves to a DIFFERENT branch: banner re-appears.
    $fake->result = new CurrentHeadResult(branch: 'feature-y', sha: $otherBranchSha, detached: false, targetExists: true);
    $component->call('checkHeadDivergence');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);
    expect($component->get('divergenceContext.currentBranch'))->toBe('feature-y');
});

test('switchReviewToHead persists the new branch and clears the banner', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'c'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);

    $component->call('switchReviewToHead');

    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    expect($component->get('projectBranch'))->toBe('feature-x');
    expect($this->project->fresh()->branch)->toBe('feature-x');
});

test('head-divergence-transitioned event triggers checkHeadDivergence on review-page', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    $fake = bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);

    // Simulate the child poller detecting a change and dispatching to the parent.
    $fake->result = new CurrentHeadResult(branch: 'feature-x', sha: 'b'.str_repeat('0', 39), detached: false, targetExists: true);
    $component->dispatch('head-divergence-transitioned');

    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);
    expect($component->get('divergenceContext.currentBranch'))->toBe('feature-x');
});

test('clearing the last persisted comment while HEAD is diverged auto-follows to HEAD', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);

    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);

    $component->set('comments', [[
        'id' => 'c1',
        'fileId' => 'abc123',
        'file' => 'src/Foo.php',
        'side' => 'new',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'hello',
        'isDraft' => false,
    ]]);
    $component->call('clearAllComments');

    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    expect($component->get('projectBranch'))->toBe('feature-x');
});

test('sentinel HEAD result leaves state untouched', function () {
    $fake = bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);

    // Simulate transient git failure.
    $fake->result = new CurrentHeadResult(branch: null, sha: '', detached: false);
    $component->call('checkHeadDivergence');

    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
});

test('switchReviewToHead is undoable and undo restores the original target', function () {
    Comment::create([
        'id' => 'c1',
        'project_id' => $this->project->id,
        'repo_path' => $this->project->path,
        'origin_ref' => 'main',
        'file_path' => 'src/Foo.php',
        'side' => 'new',
        'start_line' => 1,
        'end_line' => 1,
        'file_content_hash' => 'mock-hash',
        'body' => 'hello',
        'is_draft' => false,
        'submitted_at' => now(),
    ]);

    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'c'.str_repeat('0', 39), detached: false, targetExists: true));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::Diverged);

    $component->call('switchReviewToHead')
        ->assertDispatched('undo-available', type: 'switch-branch', payload: ['fromBranch' => 'main']);

    expect($component->get('projectBranch'))->toBe('feature-x');
    expect($this->project->fresh()->branch)->toBe('feature-x');

    // Undo re-points the review back to the branch it was on.
    $component->call('undo', 'switch-branch', ['fromBranch' => 'main']);
    expect($component->get('projectBranch'))->toBe('main');
    expect($this->project->fresh()->branch)->toBe('main');
});

test('dismissMissingTarget suppresses the missing-target banner for that branch', function () {
    bindFakeCurrentHeadAction(new CurrentHeadResult(branch: 'feature-x', sha: 'e'.str_repeat('0', 39), detached: false, targetExists: false));

    $component = Livewire::test('pages::review-page', ['slug' => 'divergence-test']);
    expect($component->get('divergenceState'))->toBe(DivergenceState::MissingTarget);

    $component->call('dismissMissingTarget');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
    expect($component->get('dismissedAtBranch'))->toBe('feature-x');

    // Same HEAD: stays suppressed.
    $component->call('checkHeadDivergence');
    expect($component->get('divergenceState'))->toBe(DivergenceState::Aligned);
});
