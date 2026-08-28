<?php

use App\Actions\GetFileCopyContentAction;
use App\Actions\LoadFileDiffAction;
use App\Console\Benchmark\DiffFixtureFactory;
use App\DTOs\CopyContentResult;
use App\DTOs\DiffTarget;
use App\DTOs\LoadedDiff;
use App\Enums\DiffLoadOutcome;
use App\Enums\LineType;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->file = DiffFixtureFactory::fileEntry('src/Test.php');
    $this->diffData = DiffFixtureFactory::diffData(path: 'src/Test.php');

    // Prime cache so component doesn't try to load from git
    $cacheKey = DiffCacheKey::for(0, $this->file['id'], reviewFingerprint());
    Cache::put($cacheKey, $this->diffData, 3600);

    // Mock LoadFileDiffAction so it never touches git
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): LoadedDiff
        {
            return DiffFixtureFactory::loadedDiff(path: $path);
        }
    });
});

function mountDiffFile(array $file, array $comments = [], bool $loadDiff = true): Testable
{
    $component = Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => $comments,
    ]);

    if ($loadDiff) {
        $component->call('loadFileDiff');
    }

    return $component;
}

// -- File comment form placement --

test('file comment form renders before diff content', function () {
    $html = mountDiffFile($this->file)->html();

    $formPos = strpos($html, 'x-ref="fileCommentForm"');
    $tablePos = strpos($html, 'data-testid="diff-table"');

    expect($formPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($formPos)->toBeLessThan($tablePos);
});

test('file-level saved comments render at top of file body', function () {
    $comments = [[
        'id' => 'fc-1',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'This file needs a refactor',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    $commentPos = strpos($html, 'This file needs a refactor');
    $tablePos = strpos($html, 'data-testid="diff-table"');

    expect($commentPos)->not->toBeFalse()
        ->and($tablePos)->not->toBeFalse()
        ->and($commentPos)->toBeLessThan($tablePos);
});

test('file-level saved comments use border-b class', function () {
    $comments = [[
        'id' => 'fc-2',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'Border test comment',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    // The comment-display component wraps the comment body in a div with the border class
    $commentPos = strpos($html, 'Border test comment');
    $borderPos = strrpos(substr($html, 0, $commentPos), 'border-b');

    expect($commentPos)->not->toBeFalse()
        ->and($borderPos)->not->toBeFalse();
});

test('saved comment renders copy, edit, and delete buttons', function () {
    $comments = [[
        'id' => 'saved-1',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'Saved body',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    expect($html)->toContain('data-testid="copy-comment"')
        ->and($html)->toContain('data-testid="edit-comment"')
        ->and($html)->toContain('aria-label="Delete comment"');
});

test('draft comment renders copy, edit, and delete buttons', function () {
    $comments = [[
        'id' => 'draft-1',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'Draft body',
        'isDraft' => true,
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    $draftPos = strpos($html, 'data-testid="draft-comment"');
    expect($draftPos)->not->toBeFalse();

    $slice = substr($html, $draftPos);
    expect($slice)->toContain('data-testid="copy-comment"')
        ->and($slice)->toContain('data-testid="edit-comment"')
        ->and($slice)->toContain('aria-label="Delete comment"');
});

test('single-line comment displays "Line N" label', function () {
    $comments = [[
        'id' => 'c-line',
        'fileId' => $this->file['id'],
        'file' => 'src/Test.php',
        'side' => 'right',
        'startLine' => 7,
        'endLine' => 7,
        'body' => 'Single line',
    ]];

    $html = mountDiffFile($this->file, $comments)->html();

    expect($html)->toContain('Line 7')
        ->and($html)->not->toContain('Lines 7-7');
});

test('comment count badge markup is present', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('x-text="$wire.fileComments.length"')
        ->and($html)->toContain('tabular-nums');
});

test('file comment button has x-ref', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('x-ref="fileCommentBtn"');
});

test('rendered HTML contains style block with syntax CSS', function () {
    $html = mountDiffFile($this->file)->html();

    expect($html)->toContain('<style>')
        ->and($html)->toContain('.hl-variable{color:#e36209;}')
        ->and($html)->toContain('.dark .hl-variable{color:#ffab70;}');
});

test('diff table delegates Cmd clicks to URL handling', function () {
    $html = mountDiffFile($this->file)->html();

    expect($html)->toContain('@mousemove="previewUrlAtPoint($event)"')
        ->and($html)->toContain('@mouseleave="clearUrlPreview()"')
        ->and($html)->toContain('@click="openUrlAtClick($event)"');
});

test('hovered diff URL uses a subtle wavy underline', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('::highlight(rfa-hovered-diff-url)')
        ->and($layout)->toContain('text-decoration-style: wavy;')
        ->and($layout)->toContain('text-decoration-thickness: 1px;')
        ->and($layout)->toContain('text-underline-offset: 2px;');
});

test('opens a validated diff URL in the system browser', function () {
    $shell = Shell::fake();

    mountDiffFile($this->file)->call('openExternalUrl', 'https://redsentry.com/contact');

    $shell->assertOpenedExternal('https://redsentry.com/contact');
});

// -- File header rendering --

test('file header shows rename arrow when oldPath is set', function () {
    $file = DiffFixtureFactory::fileEntry('src/NewName.php');
    $file['oldPath'] = 'src/OldName.php';

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('src/OldName.php')
        ->and($html)->toContain('→')
        ->and($html)->toContain('src/NewName.php');
});

test('file header shows symlink target when file is symlink', function () {
    $file = DiffFixtureFactory::fileEntry('AGENTS.md');
    $file['isSymlink'] = true;
    $file['symlinkTarget'] = 'CLAUDE.md';

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('CLAUDE.md');
});

test('file header shows addition and deletion counts', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', additions: 15, deletions: 8);

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('+15')
        ->and($html)->toContain('-8');
});

test('file header hides zero additions and deletions', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', additions: 0, deletions: 0);

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->not->toContain('text-gh-green')
        ->and($html)->not->toContain('text-gh-red');
});

test('discard button renders when diffTo is null', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('discard-file');
});

test('discard button hidden when diffTo is set', function () {
    $component = Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
        'diffTo' => 'abc123',
    ]);

    expect($component->html())->not->toContain('discard-file');
});

test('discard button hidden for commented status', function () {
    $file = DiffFixtureFactory::fileEntry('src/Test.php', status: 'commented');

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->not->toContain('discard-file');
});

test('discard button dispatches directly without confirm', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)
        ->toContain('discard-file')
        ->not->toContain('confirm(');
});

test('collapse event handlers reset autoExpandedForComment', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('@collapse-all-files.window="autoExpandedForComment = false');
});

// -- Data attributes for auto-scroll selection --

test('added lines have data-line-new but not data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<div[^>]*class="diff-line"[^>]*data-type="add"[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->not->toContain('data-line-old="');
    }
});

test('removed lines have data-line-old but not data-line-new', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<div[^>]*class="diff-line"[^>]*data-type="remove"[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-old="')
            ->and($row)->not->toContain('data-line-new="');
    }
});

test('context lines have both data-line-new and data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<div[^>]*class="diff-line"[^>]*data-type="context"[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->toContain('data-line-old="');
    }
});

// -- Tiered expand buttons --

function mountMultiHunkDiffFile(array $diffData, array $file): Testable
{
    app()->bind(LoadFileDiffAction::class, fn () => new class($diffData)
    {
        public function __construct(private array $diffData) {}

        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): LoadedDiff
        {
            return LoadedDiff::tryFrom($this->diffData) ?? LoadedDiff::empty($path);
        }
    });

    $component = Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ]);

    $component->call('loadFileDiff');

    return $component;
}

function buildContextHunk(int $startLine, int $lineCount = 3): array
{
    $lines = [];
    for ($i = 0; $i < $lineCount; $i++) {
        $lineNum = $startLine + $i;
        $lines[] = [
            'type' => LineType::Context,
            'content' => "// line {$lineNum}",
            'oldLineNum' => $lineNum,
            'newLineNum' => $lineNum,
            'highlightedContent' => "// line {$lineNum}",
        ];
    }

    return [
        'header' => '@@ hunk @@',
        'oldStart' => $startLine,
        'oldCount' => $lineCount,
        'newStart' => $startLine,
        'newCount' => $lineCount,
        'lines' => $lines,
    ];
}

test('tiered expand buttons render for middle gap larger than 15 lines', function () {
    // 2-hunk fixture has a 20-line gap between hunks (lines 9-28)
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // Should show tiered targets: "Show  15 · 20 hidden lines"
    expect($html)->toContain('expandGap(1, 15)')
        ->and($html)->toContain('expandGap(1)')
        ->and($html)->toContain('20 hidden lines')
        // Gap expanders carry the hunk-index anchor + keyboard refocus arming so
        // focus returns to the gap after a partial expand re-render.
        ->and($html)->toContain('data-expand-gap="1"')
        ->and($html)->toContain('armExpandRefocus($event, 1)')
        // The master "full file" expander has no gap to return to: it arms null.
        ->and($html)->toContain('armExpandRefocus($event, null)');
});

test('single expand button renders for gap of 15 or fewer lines', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $hunk0 = $diffData['hunks'][0];
    $diffData['hunks'][] = buildContextHunk($hunk0['newStart'] + $hunk0['newCount'] + 10);

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // The "Show" verb lives in the <x-diff.expand-control> shell; the button is the target.
    expect($html)->toContain('10 hidden lines')
        ->and($html)->not->toContain('expandGap(1, 15)');
});

test('tiered expand buttons render for leading gap larger than 15 lines', function () {
    // Create a single hunk that starts at line 25 (leading gap of 24 lines)
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['hunks'][0]['newStart'] = 25;
    $diffData['hunks'][0]['oldStart'] = 25;

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // Should show tiered targets for leading gap: "Show  15 · 24 hidden lines"
    expect($html)->toContain('expandGap(0, 15)')
        ->and($html)->toContain('expandGap(0)')
        ->and($html)->toContain('24 hidden lines')
        // Gap index 0 is a real anchor, not a falsy no-op.
        ->and($html)->toContain('data-expand-gap="0"')
        ->and($html)->toContain('armExpandRefocus($event, 0)');
});

test('tiered expand buttons render for trailing gap larger than 15 lines', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    // File has 50 lines total, hunk covers lines 1-8, trailing gap is 42 lines
    $diffData['newFileLineCount'] = 50;

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // Should show tiered buttons for trailing gap
    expect($html)->toContain('expandGap(1, 15)')
        ->and($html)->toContain('expandGap(1)')
        ->and($html)->toContain('hidden lines');
});

test('exactly 15-line gap shows single expand button with no tiers', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $hunk0 = $diffData['hunks'][0];
    $diffData['hunks'][] = buildContextHunk($hunk0['newStart'] + $hunk0['newCount'] + 15);

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    expect($html)->toContain('15 hidden lines')
        ->and($html)->not->toContain('expandGap(1, 15)');
});

test('16-line gap shows first tier', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $hunk0 = $diffData['hunks'][0];
    $diffData['hunks'][] = buildContextHunk($hunk0['newStart'] + $hunk0['newCount'] + 16);

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    expect($html)->toContain('expandGap(1, 15)')
        ->and($html)->toContain('expandGap(1)')
        ->and($html)->toContain('16')
        ->and($html)->toContain('hidden lines');
});

test('1-line gap shows single expand button', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $hunk0 = $diffData['hunks'][0];
    $diffData['hunks'][] = buildContextHunk($hunk0['newStart'] + $hunk0['newCount'] + 1);

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    expect($html)->toContain('1 hidden line')
        ->and($html)->not->toContain('1 hidden lines')
        ->and($html)->not->toContain('&middot;');
});

// -- Expand loading spinner settles on no-op early returns --

test('expandGap writes a readable envelope back to the cache', function () {
    $cacheKey = DiffCacheKey::for(0, $this->file['id'], reviewFingerprint());

    Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ])->call('expandGap', 0);

    expect(LoadedDiff::tryFrom(Cache::get($cacheKey))?->outcome)->toBe(DiffLoadOutcome::Loaded);
});

test('expandContext replaces the diff with a readable envelope', function () {
    $component = Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ])->call('expandContext');

    $component->assertDispatched('rfa:diff-action-completed', action: 'expandContext');

    expect(LoadedDiff::tryFrom(Cache::get(DiffCacheKey::for(0, $this->file['id'], reviewFingerprint()))))->not->toBeNull();
});

test('expandGap settles the action when the diff is no longer cached', function () {
    // Force diffData to hydrate null: the cached diff was evicted between the
    // render that showed the expander and this click. expandGap hits its first
    // guard, but must still dispatch so the client clears the optimistic spinner
    // (and the paired runtime-diagnostics start mark isn't left orphaned).
    Cache::forget(DiffCacheKey::for(0, $this->file['id'], reviewFingerprint()));

    Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ])->call('expandGap', 1)
        ->assertDispatched('rfa:diff-action-completed', action: 'expandGap');
});

test('expandGap settles the action when the full-context reload finds no diff', function () {
    // diffData hydrates from the primed cache (has hunks -> first guard passes),
    // but the full-context reload returns no hunks: the working tree changed under
    // the cached diff. The keyed row morphs nothing, so expandGap must dispatch
    // the completion event itself or the spinner stays stuck until a refresh.
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): LoadedDiff
        {
            return $contextLines >= 99999
                ? LoadedDiff::empty($path)
                : (DiffFixtureFactory::loadedDiff(path: $path));
        }
    });

    Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ])->call('expandGap', 1)
        ->assertDispatched('rfa:diff-action-completed', action: 'expandGap');
});

test('expandGap settles a tiered-chip expand when the reload finds no diff', function () {
    // The tiered chips call expandGap($hunkIndex, $tier); that partial-expand arg
    // shape must settle through the same no-op early return as the full "N hidden
    // lines" button (expandGap($hunkIndex)), or clicking a tier leaves the spinner
    // stuck. Guards a regression that only settles when $lineCount is null.
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): LoadedDiff
        {
            return $contextLines >= 99999
                ? LoadedDiff::empty($path)
                : (DiffFixtureFactory::loadedDiff(path: $path));
        }
    });

    Livewire::test('diff-file', [
        'file' => $this->file,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
    ])->call('expandGap', 1, 15)
        ->assertDispatched('rfa:diff-action-completed', action: 'expandGap');
});

test('gap expand-control clears its loading spinner when the action completes', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // The spinner is reset by the completion event rather than the post-expand
    // morph, so it survives the no-op early-return paths above — and is scoped to
    // this file's id so a sibling file's expand can't clear it.
    expect($html)->toContain('@rfa:diff-action-completed.window="if (String($event.detail.fileId) === String(fileId)) loading = false"');
});

// -- Copy content forwarding --

test('copyContent forwards the file status to GetFileCopyContentAction', function () {
    $captured = (object) ['kind' => null, 'status' => null];

    app()->bind(GetFileCopyContentAction::class, fn () => new class($captured)
    {
        public function __construct(private object $captured) {}

        public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null): CopyContentResult
        {
            $this->captured->kind = $kind;
            $this->captured->status = $status;

            return CopyContentResult::unavailable();
        }
    });

    $deletedFile = DiffFixtureFactory::fileEntry('src/Gone.php', status: 'deleted');

    mountDiffFile($deletedFile)->call('copyContent', 'original');

    expect($captured->kind)->toBe('original')
        ->and($captured->status)->toBe('deleted');
});

test('copyContent copies the content and toasts success when the result is ok', function () {
    app()->bind(GetFileCopyContentAction::class, fn () => new class
    {
        public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null): CopyContentResult
        {
            return CopyContentResult::ok('the new body');
        }
    });

    mountDiffFile(DiffFixtureFactory::fileEntry('src/Foo.php'))
        ->call('copyContent', 'new')
        ->assertDispatched('copy-to-clipboard', text: 'the new body', toast: 'Copied new');
});

test('copyContent surfaces a feedback toast and no clipboard event when nothing is available', function () {
    app()->bind(GetFileCopyContentAction::class, fn () => new class
    {
        public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null): CopyContentResult
        {
            return CopyContentResult::unavailable();
        }
    });

    $deletedFile = DiffFixtureFactory::fileEntry('src/Gone.php', status: 'deleted');

    mountDiffFile($deletedFile)
        ->call('copyContent', 'original')
        ->assertNotDispatched('copy-to-clipboard')
        ->assertDispatched('toast-show');
});

test('copyContent reports a too-large source with its size', function () {
    app()->bind(GetFileCopyContentAction::class, fn () => new class
    {
        public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null): CopyContentResult
        {
            return CopyContentResult::tooLarge(2_000_000);
        }
    });

    mountDiffFile(DiffFixtureFactory::fileEntry('src/Big.php'))
        ->call('copyContent', 'new')
        ->assertNotDispatched('copy-to-clipboard')
        ->assertDispatched(
            'toast-show',
            fn (string $event, array $params): bool => str_contains($params['slots']['text'] ?? '', 'too large')
                && str_contains($params['slots']['text'] ?? '', 'MB'),
        );
});

// -- Image diff source refs --

test('image diff before-image loads the base ref in a base..working review', function () {
    $imageFile = [
        'id' => 'img-1',
        'path' => 'logo.png',
        'oldPath' => null,
        'status' => 'modified',
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => true,
        'isImage' => true,
        'isUntracked' => false,
        'isSymlink' => false,
        'symlinkTarget' => null,
        'lastModified' => null,
        'fileSize' => '12 KB',
        'isExternal' => false,
        'externalAbsolutePath' => null,
    ];

    $html = Livewire::test('diff-file', [
        'file' => $imageFile,
        'repoPath' => '/tmp/test',
        'projectId' => 0,
        'fileComments' => [],
        'diffFrom' => 'base1234',
        'diffTo' => null,
    ])->html();

    // Before side must resolve to the from-ref (the base SHA), not HEAD; after
    // side is the working tree. Hardcoding HEAD broke "review since base".
    expect($html)->toContain('/api/image/0/base1234/logo.png')
        ->and($html)->toContain('/api/image/0/working/logo.png')
        ->and($html)->not->toContain('/api/image/0/HEAD/');
});
