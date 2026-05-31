<?php

use App\Actions\BuildDiffContextAction;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_context_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\necho 'world';\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('builds context snippets for commented lines', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'changed';\necho 'world';\n");

    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-1',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 2,
        'endLine' => 2,
        'body' => 'test',
    ]];

    $action = app(BuildDiffContextAction::class);
    $context = $action->handle($this->tmpDir, $comments, $files);

    expect($context)->toHaveKey('hello.php:right:2:2');
    expect($context['hello.php:right:2:2'])->toContain("echo 'changed'");
});

test('skips file-level comments (null startLine)', function () {
    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-1',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'file',
        'startLine' => null,
        'endLine' => null,
        'body' => 'general note',
    ]];

    $action = app(BuildDiffContextAction::class);
    $context = $action->handle($this->tmpDir, $comments, $files);

    expect($context)->toBeEmpty();
});

test('skips tooLarge files gracefully', function () {
    File::put($this->tmpDir.'/hello.php', str_repeat("long line\n", 500));

    config(['rfa.diff_max_bytes' => 100]);

    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-1',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'test',
    ]];

    $action = app(BuildDiffContextAction::class);
    $context = $action->handle($this->tmpDir, $comments, $files);

    expect($context)->not->toHaveKey('hello.php:right:1:1');
});

test('left-side context excludes added lines whose new-line number lands in the old range', function () {
    // Insert a line above the commented region so old-line N and new-line N
    // diverge. An Add line at new-line 3 must NOT leak into a left-side comment
    // anchored at old lines 3-4 — it has no presence on the old side.
    File::put($this->tmpDir.'/shift.php', "<?php\necho 'AAA';\necho 'BBB';\necho 'CCC';\necho 'DDD';\n");
    $this->commitTestRepo($this->tmpDir, 'base for shift');

    File::put($this->tmpDir.'/shift.php', "<?php\necho 'AAA';\necho 'INS';\necho 'BBB';\necho 'CCC';\necho 'DDD';\n");

    $fileId = 'file-'.hash('xxh128', 'shift.php');
    $files = [['id' => $fileId, 'path' => 'shift.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-1',
        'fileId' => $fileId,
        'file' => 'shift.php',
        'side' => 'left',
        'startLine' => 3,
        'endLine' => 4,
        'body' => 'old lines BBB and CCC',
    ]];

    $action = app(BuildDiffContextAction::class);
    $context = $action->handle($this->tmpDir, $comments, $files);

    $snippet = $context['shift.php:left:3:4'];
    expect($snippet)->toContain("echo 'BBB'");
    expect($snippet)->toContain("echo 'CCC'");
    expect($snippet)->not->toContain("echo 'INS'");
});

test('skips comments for unknown file ids', function () {
    $comments = [[
        'id' => 'c-1',
        'fileId' => 'file-nonexistent',
        'file' => 'gone.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'orphan',
    ]];

    $action = app(BuildDiffContextAction::class);
    $context = $action->handle($this->tmpDir, $comments, []);

    expect($context)->toBeEmpty();
});
