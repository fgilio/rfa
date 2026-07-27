<?php

use App\Actions\ExportReviewAction;
use App\DTOs\DiffTarget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->tmpDir = $this->createTempDirectory('rfa_export_action_test_');

    $this->initTestRepo($this->tmpDir);

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");
    $this->commitTestRepo($this->tmpDir, 'init');
});

test('exports markdown and clipboard text', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'changed';\n");

    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-1',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 2,
        'endLine' => 2,
        'body' => 'needs fix',
        'replies' => [[
            'id' => 'r-secret',
            'commentId' => 'c-1',
            'authorType' => 'agent',
            'authorKey' => 'codex-cli',
            'body' => 'reply must not export',
        ]],
    ]];

    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, $comments, 'overall feedback', $files);

    expect($result)->toHaveKeys(['md', 'clipboard', 'submittedIds']);
    expect(File::exists($result['md']))->toBeTrue();
    expect($result['clipboard'])->toContain('.rfa/');

    $md = File::get($result['md']);
    expect($md)->toContain('needs fix');
    expect($md)->toContain('overall feedback');
    expect($md)->toContain('hello.php');
    expect($md)->not->toContain('reply must not export');
});

test('handles empty comments', function () {
    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, [], 'just a note', []);

    expect($result)->toHaveKeys(['md', 'clipboard', 'submittedIds']);
    expect(File::exists($result['md']))->toBeTrue();
});

test('excludes unplaced comments and reports them instead of dropping them silently', function () {
    File::put($this->tmpDir.'/hello.php', "<?php\necho 'changed';\n");

    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [
        ['id' => 'c-placed', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 2, 'endLine' => 2, 'body' => 'in scope', 'anchorStatus' => 'placed'],
        ['id' => 'c-unplaced', 'fileId' => $fileId, 'file' => 'hello.php', 'side' => 'right', 'startLine' => 2, 'endLine' => 2, 'body' => 'out of scope', 'anchorStatus' => 'unplaced'],
    ];

    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, $comments, '', $files, DiffTarget::workingDirectory());

    expect($result['submittedIds'])->toBe(['c-placed']);
    expect($result['excludedComments'])->toHaveCount(1);
    expect($result['excludedComments'][0]['id'])->toBe('c-unplaced');
    expect(File::get($result['md']))->not->toContain('out of scope');
});

test('exports skipped diff reason for comments on tooLarge files', function () {
    File::put($this->tmpDir.'/hello.php', str_repeat("long line\n", 500));
    config(['rfa.diff_max_bytes' => 100]);

    $fileId = 'file-'.hash('xxh128', 'hello.php');
    $files = [['id' => $fileId, 'path' => 'hello.php', 'isUntracked' => false]];
    $comments = [[
        'id' => 'c-large',
        'fileId' => $fileId,
        'file' => 'hello.php',
        'side' => 'right',
        'startLine' => 1,
        'endLine' => 1,
        'body' => 'still review this',
    ]];

    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, $comments, '', $files);

    $md = File::get($result['md']);
    expect($md)->toContain('[Diff skipped: too-large]')
        ->and($md)->toContain('still review this');
});
