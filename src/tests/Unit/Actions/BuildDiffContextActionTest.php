<?php

use App\Actions\BuildDiffContextAction;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_context_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
    ]));

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\necho 'world';\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
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
