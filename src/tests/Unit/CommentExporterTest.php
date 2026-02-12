<?php

use App\DTOs\Comment;
use App\Services\CommentExporter;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->exporter = new CommentExporter;
    $this->tmpDir = sys_get_temp_dir().'/rfa_test_'.getmypid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    // Clean up
    $rfaDir = $this->tmpDir.'/.rfa';
    if (is_dir($rfaDir)) {
        array_map('unlink', glob($rfaDir.'/*'));
        rmdir($rfaDir);
    }
    rmdir($this->tmpDir);
});

test('exports JSON with schema version', function () {
    $comments = [
        new Comment('c1', 'app.php', 'right', 10, 10, 'Fix this'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, 'Overall looks good');

    expect($result['json'])->toMatch('/\/\.rfa\/\d{8}_\d{6}_comments_/');
    expect(file_exists($result['json']))->toBeTrue();

    $json = json_decode(file_get_contents($result['json']), true);
    expect($json['schema_version'])->toBe(1);
    expect($json['global_comment'])->toBe('Overall looks good');
    expect($json['comments'])->toHaveCount(1);
    expect($json['comments'][0]['file'])->toBe('app.php');
    expect($json['comments'][0]['start_line'])->toBe(10);
    expect($json['comments'][0]['body'])->toBe('Fix this');
    expect($json['markdown_file'])->toMatch('/^\.rfa\/\d{8}_\d{6}_comments_.*\.md$/');
});

test('exports Markdown with file grouping', function () {
    $comments = [
        new Comment('c1', 'app.php', 'right', 10, 10, 'Fix this'),
        new Comment('c2', 'app.php', 'right', 20, 25, 'Refactor this block'),
        new Comment('c3', 'config.php', 'right', 5, 5, 'Wrong value'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, 'Good work');

    $md = file_get_contents($result['md']);
    expect($md)->toMatch('/^<!-- json: \.rfa\/\d{8}_\d{6}_comments_[a-f0-9]+\.json -->/');
    expect($md)->toContain('# Code Review Comments');
    expect($md)->toContain('## General');
    expect($md)->toContain('Good work');
    expect($md)->toContain('## `app.php`');
    expect($md)->toContain('## `config.php`');
    expect($md)->toContain('**Line 10**');
    expect($md)->toContain('**Lines 20-25**');
});

test('returns clipboard text', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    expect($result['clipboard'])->toMatch('/^review my comments on these changes in @\.rfa\/\d{8}_\d{6}_comments_.*\.md$/');
});

test('creates .rfa directory if missing', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

    expect(is_dir($this->tmpDir.'/.rfa'))->toBeTrue();
});

test('handles empty comments', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

    $md = file_get_contents($result['md']);
    expect($md)->toContain('# Code Review Comments');
    expect($md)->not->toContain('## `');
});

test('uses timestamp prefix in filenames', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    expect(basename($result['json']))->toMatch('/^\d{8}_\d{6}_comments_[a-f0-9]{8}\.json$/');
    expect(basename($result['md']))->toMatch('/^\d{8}_\d{6}_comments_[a-f0-9]{8}\.md$/');
});

test('cross-references are consistent between files', function () {
    $result = $this->exporter->export($this->tmpDir, [], 'test');

    $json = json_decode(file_get_contents($result['json']), true);
    expect($json['markdown_file'])->toBe('.rfa/'.basename($result['md']));

    $md = file_get_contents($result['md']);
    $firstLine = strtok($md, "\n");
    preg_match('/<!-- json: (.+?) -->/', $firstLine, $matches);
    expect($matches[1])->toBe('.rfa/'.basename($result['json']));
});
