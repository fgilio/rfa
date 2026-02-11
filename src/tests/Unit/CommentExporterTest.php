<?php

use App\DTOs\Comment;
use App\Services\CommentExporter;

beforeEach(function () {
    $this->exporter = new CommentExporter;
    $this->tmpDir = sys_get_temp_dir() . '/rfa_test_' . getmypid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    // Clean up
    $rfaDir = $this->tmpDir . '/rfa';
    if (is_dir($rfaDir)) {
        array_map('unlink', glob($rfaDir . '/*'));
        rmdir($rfaDir);
    }
    rmdir($this->tmpDir);
});

test('exports JSON with schema version', function () {
    $comments = [
        new Comment('c1', 'app.php', 'right', 10, 10, 'Fix this'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, 'Overall looks good');

    expect($result['json'])->toContain('/rfa/comments_');
    expect(file_exists($result['json']))->toBeTrue();

    $json = json_decode(file_get_contents($result['json']), true);
    expect($json['schema_version'])->toBe(1);
    expect($json['global_comment'])->toBe('Overall looks good');
    expect($json['comments'])->toHaveCount(1);
    expect($json['comments'][0]['file'])->toBe('app.php');
    expect($json['comments'][0]['start_line'])->toBe(10);
    expect($json['comments'][0]['body'])->toBe('Fix this');
});

test('exports Markdown with file grouping', function () {
    $comments = [
        new Comment('c1', 'app.php', 'right', 10, 10, 'Fix this'),
        new Comment('c2', 'app.php', 'right', 20, 25, 'Refactor this block'),
        new Comment('c3', 'config.php', 'right', 5, 5, 'Wrong value'),
    ];

    $result = $this->exporter->export($this->tmpDir, $comments, 'Good work');

    $md = file_get_contents($result['md']);
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

    expect($result['clipboard'])->toStartWith('review my comments on these changes in @rfa/comments_');
    expect($result['clipboard'])->toEndWith('.md');
});

test('creates rfa directory if missing', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

    expect(is_dir($this->tmpDir . '/rfa'))->toBeTrue();
});

test('handles empty comments', function () {
    $result = $this->exporter->export($this->tmpDir, [], '');

    $md = file_get_contents($result['md']);
    expect($md)->toContain('# Code Review Comments');
    expect($md)->not->toContain('## `');
});
