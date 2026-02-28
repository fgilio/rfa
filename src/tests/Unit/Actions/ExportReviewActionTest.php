<?php

use App\Actions\ExportReviewAction;
use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir().'/rfa_export_action_test_'.uniqid();
    File::makeDirectory($this->tmpDir, 0755, true);

    exec(implode(' && ', [
        'cd '.escapeshellarg($this->tmpDir),
        'git init -b main',
        "git config user.email 'test@rfa.test'",
        "git config user.name 'RFA Test'",
        'git config commit.gpgsign false',
    ]));

    File::put($this->tmpDir.'/hello.php', "<?php\necho 'hello';\n");
    exec('cd '.escapeshellarg($this->tmpDir).' && git add -A && git commit -m init');
});

afterEach(function () {
    File::deleteDirectory($this->tmpDir);
});

test('exports JSON, markdown, and clipboard text', function () {
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
    ]];

    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, $comments, 'overall feedback', $files);

    expect($result)->toHaveKeys(['json', 'md', 'clipboard']);
    expect(File::exists($result['json']))->toBeTrue();
    expect(File::exists($result['md']))->toBeTrue();
    expect($result['clipboard'])->toContain('.rfa/');

    $md = File::get($result['md']);
    expect($md)->toContain('needs fix');
    expect($md)->toContain('overall feedback');
    expect($md)->toContain('hello.php');
});

test('handles empty comments', function () {
    $action = app(ExportReviewAction::class);
    $result = $action->handle($this->tmpDir, [], 'just a note', []);

    expect($result)->toHaveKeys(['json', 'md', 'clipboard']);
    expect(File::exists($result['json']))->toBeTrue();
});
