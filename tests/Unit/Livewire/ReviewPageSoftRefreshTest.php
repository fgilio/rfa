<?php

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\SessionStateAction;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DiffTarget;
use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Models\Project;
use App\Services\GitFileContentService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
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
            ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100', 'mtime' => 1714000000, 'byteSize' => 100],
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
    $tokenAfterMount = $component->get('diffRefreshToken');

    $component->call('softRefresh')
        ->assertDispatched('fingerprint-reset')
        ->assertDispatched('refresh-completed', changedCount: 0);

    expect($this->fileListFake->callCount)->toBeGreaterThan($callsAfterMount);
    expect($component->get('diffRefreshToken'))->toBe($tokenAfterMount + 1);
});

test('native refresh shortcut routes through softRefresh', function () {
    Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test'])
        ->dispatch('native:App\\Events\\RefreshShortcutPressed', RefreshShortcutPressed::KEY)
        ->assertDispatched('refresh-completed', changedCount: 0);
});

test('native hard reload shortcut requests a browser reload', function () {
    Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test'])
        ->dispatch('native:App\\Events\\HardReloadShortcutPressed', HardReloadShortcutPressed::KEY)
        ->assertDispatched('hard-reload-requested');
});

test('softRefresh reports changedCount when files differ', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 7, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '120', 'mtime' => 1714000100, 'byteSize' => 120],
        ['id' => 'def456', 'path' => 'src/Bar.php', 'status' => 'added', 'oldPath' => null, 'additions' => 10, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '200', 'mtime' => 1714000100, 'byteSize' => 200],
    ];

    $component->call('softRefresh')
        ->assertDispatched('fingerprint-reset')
        ->assertDispatched('refresh-completed', changedCount: 2);
});

test('softRefresh keeps unchanged file refresh fingerprints stable', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);
    $fingerprintAfterMount = $component->get('sourceFiles')[0]['refreshFingerprint'];

    $component->call('softRefresh');

    expect($component->get('sourceFiles')[0]['refreshFingerprint'])->toBe($fingerprintAfterMount);
});

test('softRefresh updates changed file refresh fingerprints', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);
    $fingerprintAfterMount = $component->get('sourceFiles')[0]['refreshFingerprint'];

    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100', 'mtime' => 1714000042, 'byteSize' => 100],
    ];

    $component->call('softRefresh');

    expect($component->get('sourceFiles')[0]['refreshFingerprint'])->not->toBe($fingerprintAfterMount);
});

test('softRefresh preserves activeFileId across the call', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test'])
        ->set('activeFileId', 'abc123');

    $component->call('softRefresh');

    expect($component->get('activeFileId'))->toBe('abc123');
});

test('softRefresh renders the new file list when files change', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100', 'mtime' => 1714000000, 'byteSize' => 100],
        ['id' => 'def456', 'path' => 'src/NewlyAdded.php', 'status' => 'added', 'oldPath' => null, 'additions' => 10, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T01:00:00Z', 'fileSize' => '200', 'mtime' => 1714000100, 'byteSize' => 200],
    ];

    $component->call('softRefresh')
        ->assertDispatched('refresh-completed', changedCount: 1)
        ->assertSee('src/NewlyAdded.php');
});

/**
 * Contract: softRefresh always renders, even when nothing visible changed.
 * The previous implementation called `skipRender()` based on a
 * fingerprint heuristic; that was the root cause of the 1commit+WC
 * stale-diff bug, because a fingerprint false-negative latched the
 * response into a no-op morph and children never re-hydrated from the
 * (already-cleared) cache. softRefresh is user-initiated (⌘R or click),
 * so the cost of an idempotent render is acceptable; the cost of a
 * silently stale UI is not.
 */
test('softRefresh always renders even when no files changed', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $component->call('softRefresh')
        ->assertDispatched('refresh-completed', changedCount: 0);

    expect($component->effects['html'] ?? null)->not->toBeNull();
});

test('softRefresh exposes the diff refresh token in rendered markup', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    $component->call('softRefresh');

    $token = $component->get('diffRefreshToken');

    $component->assertSeeHtml('data-diff-refresh-token="'.$token.'"');
});

test('softRefresh logs a canonical refresh event', function () {
    Log::spy();

    Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test'])
        ->call('softRefresh');

    Log::shouldHaveReceived('info')
        ->with('review.refreshed')
        ->once();

    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.project_slug'))->toBe('soft-refresh-test')
        ->and(Context::get('rfa.target'))->toBe('HEAD..working')
        ->and(Context::get('rfa.changed_file_ids_count'))->toBe(0)
        ->and(Context::get('rfa.changed_file_ids_truncated'))->toBeFalse();
});

test('softRefresh logs error outcome when refresh fails', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    app()->bind(SessionStateAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
        {
            throw new RuntimeException('Refresh failed');
        }

        public function saveGlobalNote(string $repoPath, string $globalComment, ?int $projectId = null): void {}
    });

    Log::spy();

    expect(fn () => $component->call('softRefresh'))->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('info')
        ->with('review.refreshed')
        ->once();

    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.error_class'))->toBe(RuntimeException::class)
        ->and(Context::get('rfa.file_count_after'))->toBeNull()
        ->and(Context::get('rfa.changed_count'))->toBeNull();
});

/**
 * Toast accuracy regression: in 1commit+WC and Since-base modes an
 * in-place WC edit can leave numstat additions/deletions unchanged (the
 * line was already counted by some pinned commit) and the human-readable
 * `lastModified` / `fileSize` strings can bucket identically across two
 * rapid refreshes. The fingerprint must still detect the edit via raw
 * mtime / byte size — otherwise the toast says "Up to date" while the
 * diff did, in fact, change. (The render itself no longer depends on
 * this signal, but the toast text does.)
 */
test('softRefresh changedCount catches in-place edits via raw mtime when status/additions/lastModified are stable', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    // Same id, status, additions, deletions, and human-readable
    // lastModified/fileSize as the mount fixture — only the raw mtime
    // bumps, simulating an in-place edit between two refreshes.
    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T00:00:00Z', 'fileSize' => '100', 'mtime' => 1714000042, 'byteSize' => 100],
    ];

    $component->call('softRefresh')
        ->assertDispatched('refresh-completed', changedCount: 1);

    expect($component->effects['html'] ?? null)->not->toBeNull();
});

test('head-advanced-on-branch triggers softRefresh and dispatches refresh-completed', function () {
    $component = Livewire::test('pages::review-page', ['slug' => 'soft-refresh-test']);

    // Simulate a new commit landing on the branch the user is reviewing —
    // the file list now reflects post-commit state.
    $this->fileListFake->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 12, 'deletions' => 3, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T02:00:00Z', 'fileSize' => '150', 'mtime' => 1714000200, 'byteSize' => 150],
        ['id' => 'new789', 'path' => 'src/JustCommitted.php', 'status' => 'added', 'oldPath' => null, 'additions' => 20, 'deletions' => 0, 'isBinary' => false, 'isUntracked' => false, 'lastModified' => '2026-04-24T02:00:00Z', 'fileSize' => '300', 'mtime' => 1714000200, 'byteSize' => 300],
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
