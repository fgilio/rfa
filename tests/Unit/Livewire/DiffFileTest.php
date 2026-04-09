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
    $tablePos = strpos($html, '<table');

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
    $tablePos = strpos($html, '<table');

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

test('discard confirm mentions comment count', function () {
    $comments = [
        ['id' => 'c1', 'fileId' => $this->file['id'], 'file' => 'src/Test.php', 'side' => 'file', 'startLine' => null, 'endLine' => null, 'body' => 'A'],
        ['id' => 'c2', 'fileId' => $this->file['id'], 'file' => 'src/Test.php', 'side' => 'file', 'startLine' => null, 'endLine' => null, 'body' => 'B'],
    ];

    $html = mountDiffFile($this->file, $comments, loadDiff: false)->html();

    expect($html)->toContain('remove 2 comments');
});

test('collapse event handlers reset autoExpandedForComment', function () {
    $html = mountDiffFile($this->file, loadDiff: false)->html();

    expect($html)->toContain('@collapse-all-files.window="autoExpandedForComment = false');
});

// -- Data attributes for auto-scroll selection --

test('added lines have data-line-new but not data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*bg-gh-add-bg[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->not->toContain('data-line-old="');
    }
});

test('removed lines have data-line-old but not data-line-new', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*bg-gh-del-bg[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-old="')
            ->and($row)->not->toContain('data-line-new="');
    }
});

test('context lines have both data-line-new and data-line-old', function () {
    $html = mountDiffFile($this->file)->html();

    preg_match_all('/<tr[^>]*class="diff-line\s*"[^>]*>/', $html, $matches);
    expect($matches[0])->not->toBeEmpty();

    foreach ($matches[0] as $row) {
        expect($row)->toContain('data-line-new="')
            ->and($row)->toContain('data-line-old="');
    }
});

// -- Tiered expand buttons --

function buildFullContextDiff(int $totalLines, string $path = 'src/Test.php'): array
{
    $lines = [];
    for ($i = 1; $i <= $totalLines; $i++) {
        $lines[] = [
            'type' => 'context',
            'content' => "    // line {$i}",
            'oldLineNum' => $i,
            'newLineNum' => $i,
            'highlightedContent' => "// line {$i}",
        ];
    }

    return [
        'path' => $path,
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [[
            'header' => '',
            'oldStart' => 1,
            'oldCount' => $totalLines,
            'newStart' => 1,
            'newCount' => $totalLines,
            'lines' => $lines,
        ]],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'tooLarge' => false,
        'syntaxStyles' => '',
    ];
}

function mountMultiHunkDiffFile(array $diffData, array $file, ?array $fullDiff = null): Testable
{
    // Rebind mock to return the specific diff data (and optionally a full diff for expandGap)
    app()->bind(LoadFileDiffAction::class, fn () => new class($diffData, $fullDiff)
    {
        public function __construct(private array $diffData, private ?array $fullDiff) {}

        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
        {
            return ($contextLines >= 99999 && $this->fullDiff !== null)
                ? $this->fullDiff
                : $this->diffData;
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

    // Should show tiered buttons: "Expand 15 · 20 hidden lines"
    expect($html)->toContain('expandGap(1, 15)')
        ->and($html)->toContain('expandGap(1)')
        ->and($html)->toContain('&middot;')
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

test('partial middle gap expansion preserves separate hunks', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');
    $fullDiff = buildFullContextDiff(36, 'src/Test.php');

    $component = mountMultiHunkDiffFile($diffData, $this->file, $fullDiff);
    $instance = $component->instance();
    $ref = new ReflectionProperty($instance, 'diffData');
    $ref->setValue($instance, $diffData);

    // Expand 15 of the 20-line gap between hunks
    $instance->expandGap(1, 15);

    $result = $ref->getValue($instance);
    expect($result['hunks'])->toHaveCount(2);
    expect($result['hunks'][0]['newCount'])->toBe($diffData['hunks'][0]['newCount'] + 15);
    $remainingGap = $result['hunks'][1]['newStart'] - ($result['hunks'][0]['newStart'] + $result['hunks'][0]['newCount']);
    expect($remainingGap)->toBe(5);
    // Lines 9-23 (top of gap) appended to hunk 0
    $originalLineCount = count($diffData['hunks'][0]['lines']);
    expect($result['hunks'][0]['lines'][$originalLineCount]['newLineNum'])->toBe(9);
    expect($result['hunks'][0]['lines'][$originalLineCount + 14]['newLineNum'])->toBe(23);
});

test('full middle gap expansion merges hunks into one', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');
    $fullDiff = buildFullContextDiff(36, 'src/Test.php');

    $component = mountMultiHunkDiffFile($diffData, $this->file, $fullDiff);
    $instance = $component->instance();
    $ref = new ReflectionProperty($instance, 'diffData');
    $ref->setValue($instance, $diffData);

    $instance->expandGap(1);

    $result = $ref->getValue($instance);
    expect($result['hunks'])->toHaveCount(1);
    $newLineNums = array_column($result['hunks'][0]['lines'], 'newLineNum');
    foreach (range(9, 28) as $expected) {
        expect($newLineNums)->toContain($expected);
    }
});

test('partial leading gap expansion shrinks the gap', function () {
    // Hunk starts at line 25, so 24-line leading gap
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['hunks'][0]['newStart'] = 25;
    $diffData['hunks'][0]['oldStart'] = 25;

    $fullDiff = buildFullContextDiff(40, 'src/Test.php');

    $component = mountMultiHunkDiffFile($diffData, $this->file, $fullDiff);
    $instance = $component->instance();
    $ref = new ReflectionProperty($instance, 'diffData');
    $ref->setValue($instance, $diffData);

    $instance->expandGap(0, 15);

    $result = $ref->getValue($instance);
    expect($result['hunks'][0]['newStart'])->toBe(10)
        ->and($result['hunks'][0]['newCount'])->toBe($diffData['hunks'][0]['newCount'] + 15);
    // Lines 10-24 (bottom of gap, closest to hunk) prepended
    expect($result['hunks'][0]['lines'][0]['newLineNum'])->toBe(10);
    expect($result['hunks'][0]['lines'][14]['newLineNum'])->toBe(24);
});

test('partial trailing gap expansion appends to last hunk', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['newFileLineCount'] = 50;

    $fullDiff = buildFullContextDiff(50, 'src/Test.php');

    $component = mountMultiHunkDiffFile($diffData, $this->file, $fullDiff);
    $instance = $component->instance();
    $ref = new ReflectionProperty($instance, 'diffData');
    $ref->setValue($instance, $diffData);

    $originalNewCount = $diffData['hunks'][0]['newCount'];

    $instance->expandGap(1, 15);

    $result = $ref->getValue($instance);
    expect($result['hunks'][0]['newCount'])->toBe($originalNewCount + 15);
    $lastHunk = $result['hunks'][0];
    $remainingGap = $result['newFileLineCount'] - ($lastHunk['newStart'] + $lastHunk['newCount'] - 1);
    expect($remainingGap)->toBe(27);
    // Lines 9-23 (top of gap) appended
    $originalLineCount = count($diffData['hunks'][0]['lines']);
    expect($result['hunks'][0]['lines'][$originalLineCount]['newLineNum'])->toBe(9);
    expect($result['hunks'][0]['lines'][$originalLineCount + 14]['newLineNum'])->toBe(23);
});
