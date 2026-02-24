<?php

use App\Actions\LoadFileDiffAction;
use App\Exceptions\GitCommandException;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\IgnoreService;
use App\Services\SyntaxHighlightService;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_action_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
    ]));

    File::put($this->tmpDir.'/hello.txt', "line1\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('returns full DTO array for modified file', function () {
    File::put($this->tmpDir.'/hello.txt', "line1\nline2\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
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

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
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
    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
    $result = $action->handle($this->tmpDir, 'nonexistent.txt', isUntracked: true);

    expect($result['hunks'])->toBe([])
        ->and($result['tooLarge'])->toBeFalse();
});

test('handles untracked file', function () {
    File::put($this->tmpDir.'/newfile.txt', "hello\nworld\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
    $result = $action->handle($this->tmpDir, 'newfile.txt', isUntracked: true);

    expect($result)->not->toBeNull()
        ->and($result['hunks'])->toHaveCount(1)
        ->and($result['tooLarge'])->toBeFalse()
        ->and($result['path'])->toBe('newfile.txt');
});

// -- syntax highlighting --

test('adds highlightedContent for known file types', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hi';\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m "add php"');
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
    $result = $action->handle($this->tmpDir, 'hello.php');

    expect($result)->not->toBeNull()
        ->and($result['hunks'])->toHaveCount(1);

    $hasHighlighted = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => isset($line['highlightedContent']));

    expect($hasHighlighted)->toBeTrue();
});

test('no highlightedContent for unknown file types', function () {
    File::put($this->tmpDir.'/data.xyz', "some content\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m "add xyz"');
    File::put($this->tmpDir.'/data.xyz', "updated content\n");

    $action = new LoadFileDiffAction(new GitDiffService(new IgnoreService), new DiffParser, new SyntaxHighlightService);
    $result = $action->handle($this->tmpDir, 'data.xyz');

    expect($result)->not->toBeNull();

    $hasHighlighted = collect($result['hunks'][0]['lines'])
        ->contains(fn ($line) => isset($line['highlightedContent']));

    expect($hasHighlighted)->toBeFalse();
});

// -- git error propagation --

test('returns error field when git command fails', function () {
    $gitService = Mockery::mock(GitDiffService::class);
    $gitService->shouldReceive('getFileDiff')
        ->andThrow(new GitCommandException('git diff', 'fatal: bad revision', 128));

    $action = new LoadFileDiffAction($gitService, new DiffParser, new SyntaxHighlightService);
    $result = $action->handle($this->tmpDir, 'hello.txt');

    expect($result['error'])->toBe('fatal: bad revision')
        ->and($result['hunks'])->toBe([])
        ->and($result['tooLarge'])->toBeFalse();
});
