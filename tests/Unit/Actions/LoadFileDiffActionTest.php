<?php

use App\Actions\LoadFileDiffAction;
use App\Exceptions\GitCommandException;
use App\Services\CsvAlignerService;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use App\Services\MarkdownRegionService;
use App\Services\MarkdownTableAlignerService;
use App\Services\SyntaxHighlightService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_action_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/hello.txt', "line1\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('returns full DTO array for modified file', function () {
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result)->toHaveKeys(['path', 'status', 'hunks', 'additions', 'deletions', 'isBinary', 'tooLarge'])
        ->and($result['tooLarge'])->toBeFalse()
        ->and($result['path'])->toBe('hello.txt')
        ->and($result['hunks'])->toHaveCount(1)
        ->and($result['hunks'][0])->toHaveKeys(['header', 'oldStart', 'newStart', 'lines']);
});

test('returns tooLarge true when diff exceeds limit', function () {
    File::put($this->tmpDir.'/hello.txt', str_repeat("long line\n", 500));

    // Use a very low maxBytes config
    config(['rfa.diff_max_bytes' => 100]);

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result)->toHaveKeys(['path', 'status', 'oldPath', 'hunks', 'additions', 'deletions', 'isBinary', 'tooLarge'])
        ->and($result['tooLarge'])->toBeTrue()
        ->and($result['hunks'])->toBe([])
        ->and($result['path'])->toBe('hello.txt')
        ->and($result['additions'])->toBe(0)
        ->and($result['deletions'])->toBe(0)
        ->and($result['isBinary'])->toBeFalse();
});

test('returns empty array for empty diff', function () {
    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'nonexistent.txt', isUntracked: true);

    expect($result['hunks'])->toBe([])
        ->and($result['tooLarge'])->toBeFalse();
});

test('handles untracked file', function () {
    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'newfile.txt', isUntracked: true);

    expect($result)->not->toBeNull()
        ->and($result['hunks'])->toHaveCount(1)
        ->and($result['tooLarge'])->toBeFalse()
        ->and($result['path'])->toBe('newfile.txt');
});

// -- syntax highlighting --

test('adds highlightedContent for known file types', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hi';\n");
    $this->commitTestRepo($this->tmpDir, 'add php');
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.php');

    expect($result)->not->toBeNull()
        ->and($result['hunks'])->toHaveCount(1);

    $hasHighlighted = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => isset($line['highlightedContent']));

    expect($hasHighlighted)->toBeTrue();
});

test('result contains syntaxStyles CSS for known file types', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hi';\n");
    $this->commitTestRepo($this->tmpDir, 'add php styles');
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.php');

    expect($result)->toHaveKey('syntaxStyles')
        ->and($result['syntaxStyles'])->toBeString()
        ->and($result['syntaxStyles'])->not->toBeEmpty()
        ->and($result['syntaxStyles'])->toContain('.dark ');
});

test('no highlightedContent for unknown file types', function () {
    File::put($this->tmpDir.'/data.xyz', "some content\n");
    $this->commitTestRepo($this->tmpDir, 'add xyz');
    File::put($this->tmpDir.'/data.xyz', "updated content\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'data.xyz');

    expect($result)->not->toBeNull();

    $hasHighlighted = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => isset($line['highlightedContent']));

    expect($hasHighlighted)->toBeFalse();
});

test('contextLines parameter produces single hunk for full context', function () {
    // Create file with 20 lines, modify first and last to produce 2 hunks
    $lines = array_map(fn ($i) => "line{$i}", range(1, 20));
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");
    $this->commitTestRepo($this->tmpDir, 'add many');

    $lines[0] = 'changed1';
    $lines[19] = 'changed20';
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");

    $action = new LoadFileDiffAction(
        new GitDiffService(new GitProcessService, new IgnoreService),
        new DiffParser,
        new SyntaxHighlightService,
        new MarkdownTableAlignerService,
        new CsvAlignerService,
        new MarkdownRegionService,
    );

    $default = $action->handle($this->tmpDir, 'many.txt');
    $full = $action->handle($this->tmpDir, 'many.txt', contextLines: 99999);

    expect($default['hunks'])->toHaveCount(2);
    expect($full['hunks'])->toHaveCount(1);
    expect($full['hunks'][0]['newStart'])->toBe(1);
});

// -- self-healing cache --

test('stale cache without syntaxStyles triggers re-computation', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hi';\n");
    $this->commitTestRepo($this->tmpDir, 'add php cache');
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");

    $cacheKey = 'test-stale-cache-key';

    // Seed cache with stale data missing syntaxStyles
    Cache::put($cacheKey, [
        'path' => 'hello.php',
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'tooLarge' => false,
    ], 3600);

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.php', cacheKey: $cacheKey);

    expect($result)->toHaveKey('syntaxStyles')
        ->and($result['syntaxStyles'])->toBeString()
        ->and($result['hunks'])->not->toBeEmpty();
});

test('class names in highlightedContent have matching selectors in syntaxStyles', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hi';\n");
    $this->commitTestRepo($this->tmpDir, 'add php selectors');
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.php');

    $classNames = [];
    foreach ($result['hunks'] as $hunk) {
        foreach ($hunk['lines'] as $line) {
            if (! empty($line['highlightedContent'])) {
                preg_match_all('/class="([^"]+)"/', $line['highlightedContent'], $matches);
                foreach ($matches[1] as $cls) {
                    $classNames[$cls] = true;
                }
            }
        }
    }

    expect($classNames)->not->toBeEmpty();

    $css = $result['syntaxStyles'];
    foreach (array_keys($classNames) as $cls) {
        expect($css)->toContain(".{$cls}{");
    }
});

// -- newFileLineCount --

test('result includes newFileLineCount for modified file', function () {
    // hello.txt starts as 1 line, modify to 3 lines
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\nline3\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result)->toHaveKey('newFileLineCount')
        ->and($result['newFileLineCount'])->toBe(3);
});

test('newFileLineCount reflects actual file length beyond last hunk', function () {
    // Create a 20-line file, modify only line 1 (with default 3 context lines, hunk covers ~4 lines)
    $lines = array_map(fn ($i) => "line{$i}", range(1, 20));
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");
    $this->commitTestRepo($this->tmpDir, 'add many');

    $lines[0] = 'changed1';
    File::put($this->tmpDir.'/many.txt', implode("\n", $lines)."\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'many.txt');

    expect($result['newFileLineCount'])->toBe(20);

    // Verify the last hunk ends before the file does (trailing gap exists)
    $lastHunk = end($result['hunks']);
    $lastHunkEnd = $lastHunk['newStart'] + $lastHunk['newCount'] - 1;
    expect($lastHunkEnd)->toBeLessThan(20);
});

test('stale cache without newFileLineCount triggers re-computation', function () {
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $cacheKey = 'test-stale-no-linecount';

    Cache::put($cacheKey, [
        'path' => 'hello.txt',
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'isSymlink' => false,
        'tooLarge' => false,
        'syntaxStyles' => '',
        'tableAligned' => true,
    ], 3600);

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.txt', cacheKey: $cacheKey);

    expect($result)->toHaveKey('newFileLineCount')
        ->and($result['newFileLineCount'])->toBe(2);
});

// -- markdown heading annotation --

test('markdown files get heading metadata on hunk lines', function () {
    File::put($this->tmpDir.'/doc.md', "# Title\n\nbody\n");
    $this->commitTestRepo($this->tmpDir, 'add doc');
    File::put($this->tmpDir.'/doc.md', "# Title\n\nbody updated\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'doc.md');

    $hasHeading = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => ($line['headingLevel'] ?? null) === 1);

    expect($hasHeading)->toBeTrue()
        ->and($result)->toHaveKey('headingsAnnotated');
});

test('non-markdown files do not get heading metadata', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\n# this is a PHP comment, not a heading\n");
    $this->commitTestRepo($this->tmpDir, 'add php with hash');
    File::put($this->tmpDir.'/hello.php', "<?php\n# updated comment\n");

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.php');

    $hasHeading = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => isset($line['headingLevel']));

    expect($hasHeading)->toBeFalse();
});

test('stale cache without headingsAnnotated triggers re-computation', function () {
    File::put($this->tmpDir.'/doc.md', "# New\n");
    $this->commitTestRepo($this->tmpDir, 'add md cache');
    File::put($this->tmpDir.'/doc.md', "# Changed\n");

    $cacheKey = 'test-stale-headings-key';

    Cache::put($cacheKey, [
        'path' => 'doc.md',
        'status' => 'modified',
        'oldPath' => null,
        'hunks' => [],
        'additions' => 0,
        'deletions' => 0,
        'isBinary' => false,
        'isSymlink' => false,
        'tooLarge' => false,
        'syntaxStyles' => '',
        'tableAligned' => true,
        'newFileLineCount' => 1,
    ], 3600);

    $action = new LoadFileDiffAction(new GitDiffService(new GitProcessService, new IgnoreService), new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'doc.md', cacheKey: $cacheKey);

    expect($result)->toHaveKey('headingsAnnotated')
        ->and($result['hunks'])->not->toBeEmpty();
});

// -- git error propagation --

test('returns error field when git command fails', function () {
    $gitService = Mockery::mock(GitDiffService::class);
    $gitService->shouldReceive('getFileDiff')
        ->andThrow(new GitCommandException('git diff', 'fatal: bad revision', 128));

    $action = new LoadFileDiffAction($gitService, new DiffParser, new SyntaxHighlightService, new MarkdownTableAlignerService, new CsvAlignerService, new MarkdownRegionService);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result['error'])->toBe('Failed to load diff for this file.')
        ->and($result['hunks'])->toBe([])
        ->and($result['tooLarge'])->toBeFalse();
});
