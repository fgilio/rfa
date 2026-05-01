<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\SessionStateAction;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'soft-refresh-test',
        'name' => 'Soft Refresh Test',
        'path' => '/tmp/repo',
        'git_common_dir' => '/tmp/repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => null,
        'respect_global_gitignore' => true,
    ]);

    $project = $this->project;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug, bool $touch = false): ?array
        {
            return $this->project->fresh()->toArray();
        }
    });

    // Mutable file list so tests can simulate disk changes across softRefresh calls.
    $this->fileListFake = new class
    {
        /** @var array<int, array<string, mixed>> */
        public array $files = [
            ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100'],
        ];

        public int $callCount = 0;

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
        {
            $this->callCount++;

            return $this->files;
        }
    };

    app()->instance(GetFileListAction::class, $this->fileListFake);

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

    app()->instance(GetCurrentHeadAction::class, new class
    {
        public function handle(string $repoPath, ?string $targetBranch = null): CurrentHeadResult
        {
            return new CurrentHeadResult(branch: 'main', sha: 'a'.str_repeat('0', 39), detached: false, targetExists: true);
        }
    });
});

test('softRefresh re-reads file list and dispatches refresh-completed with zero changes when nothing changed', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $callsAfterMount = $this->fileListFake->callCount;

    $component->call('softRefresh')
        ->assertDispatched('fingerprint-reset')
        ->assertDispatched('refresh-completed', changedCount: 0);

    expect($this->fileListFake->callCount)->toBeGreaterThan($callsAfterMount);
});

test('softRefresh reports changedCount when files differ', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 7, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '120'],
        ['id' => 'def456', 'path' => 'src/Bar.php', 'status' => 'added', 'oldPath' => null, 'additions' => 10, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '200'],
    ];

    $component->call('softRefresh')
        ->assertDispatched('fingerprint-reset')
        ->assertDispatched('refresh-completed', changedCount: 2);
});

test('softRefresh preserves activeFileId across the call', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test'])
        ->set('activeFileId', 'abc123');

    $component->call('softRefresh');

    expect($component->get('activeFileId'))->toBe('abc123');
});

/**
 * Regression: after mount, `divergenceChecked` is true, so an unchanged
 * divergence poll inside softRefresh would latch `skipRender()` onto the
 * response. If files also changed, the toast fires but the Blade never
 * morphs and the UI stays stale until a hard reload. softRefresh must
 * still render when `changedCount > 0`, even with stable divergence.
 */
test('softRefresh with file changes renders the new file list despite stable divergence', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    expect($component->get('divergenceChecked'))->toBeTrue();

    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100'],
        ['id' => 'def456', 'path' => 'src/NewlyAdded.php', 'status' => 'added', 'oldPath' => null, 'additions' => 10, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '200'],
    ];

    $component->call('softRefresh')
        ->assertDispatched('refresh-completed', changedCount: 1)
        ->assertSee('src/NewlyAdded.php');
});

test('softRefresh with no file changes and stable divergence skips the render', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $component->call('softRefresh');

    expect($component->effects['html'] ?? null)->toBeNull();
});

test('head-advanced-on-branch triggers softRefresh and dispatches refresh-completed', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    // Simulate a new commit landing on the branch the user is reviewing —
    // the file list now reflects post-commit state.
    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 12, 'deletions' => 3, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T02:00:00Z', 'fileSize' => '150'],
        ['id' => 'new789', 'path' => 'src/JustCommitted.php', 'status' => 'added', 'oldPath' => null, 'additions' => 20, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T02:00:00Z', 'fileSize' => '300'],
    ];

    $component->call('refreshAfterHeadAdvance')
        ->assertDispatched('fingerprint-reset')
        ->assertDispatched('refresh-completed')
        ->assertSee('src/JustCommitted.php');
});

test('head-advanced-on-branch is a no-op in commit/range mode', function () {
    $component = Livewire::test('pages::review-page', [
        'slug' => 'soft-refresh-test',
        'from' => 'aaaa111',
        'to' => 'bbbb222',
    ]);

    $component->call('refreshAfterHeadAdvance')
        ->assertNotDispatched('fingerprint-reset')
        ->assertNotDispatched('refresh-completed');
});
