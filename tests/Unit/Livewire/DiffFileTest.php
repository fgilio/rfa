<?php

use App\Actions\LoadFileDiffAction;
use App\Console\Benchmark\DiffFixtureFactory;
use App\DTOs\DiffTarget;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->file = DiffFixtureFactory::fileEntry('src/Test.php');
    $this->diffData = DiffFixtureFactory::diffData(path: 'src/Test.php');

    // Prime cache so component doesn't try to load from git
    $cacheKey = DiffCacheKey::for(0, $this->file['id']);
    Cache::put($cacheKey, $this->diffData, 3600);

    // Mock LoadFileDiffAction so it never touches git
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
        {
            return DiffFixtureFactory::diffData(path: $path);
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

// -- File header rendering --

test('file header shows rename arrow when oldPath is set', function () {
    $file = DiffFixtureFactory::fileEntry('src/NewName.php');
    $file['oldPath'] = 'src/OldName.php';

    $html = mountDiffFile($file, loadDiff: false)->html();

    expect($html)->toContain('src/OldName.php')
        ->and($html)->toContain('&rarr;')
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

        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
        {
            return $this->diffData;
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
            'type' => 'context',
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

    // Should show tiered buttons: "Expand 15  20 hidden lines"
    expect($html)->toContain('expandGap(1, 15)')
        ->and($html)->toContain('expandGap(1)')
        ->and($html)->toContain('20')
        ->and($html)->toContain('hidden lines');
});

test('single expand button renders for gap of 15 or fewer lines', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $hunk0 = $diffData['hunks'][0];
    $diffData['hunks'][] = buildContextHunk($hunk0['newStart'] + $hunk0['newCount'] + 10);

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    expect($html)->toContain('Expand 10 hidden lines')
        ->and($html)->not->toContain('expandGap(1, 15)');
});

test('tiered expand buttons render for leading gap larger than 15 lines', function () {
    // Create a single hunk that starts at line 25 (leading gap of 24 lines)
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['hunks'][0]['newStart'] = 25;
    $diffData['hunks'][0]['oldStart'] = 25;

    $html = mountMultiHunkDiffFile($diffData, $this->file)->html();

    // Should show tiered buttons for leading gap: "Expand 15 · 24 hidden lines"
    expect($html)->toContain('expandGap(0, 15)')
        ->and($html)->toContain('expandGap(0)')
        ->and($html)->toContain('24')
        ->and($html)->toContain('hidden lines');
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

    expect($html)->toContain('Expand 15 hidden lines')
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

    expect($html)->toContain('Expand 1 hidden lines')
        ->and($html)->not->toContain('&middot;');
});
