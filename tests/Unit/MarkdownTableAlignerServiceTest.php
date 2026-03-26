<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Services\MarkdownTableAlignerService;

beforeEach(function () {
    $this->aligner = new MarkdownTableAlignerService;
});

test('returns hunks unchanged for non-markdown files', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine('context', '| col1 | col2 |', 1, 1),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'file.php');

    expect($result)->toBe($hunks);
});

test('returns hunks unchanged when no tables present', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine('context', 'just some text', 1, 1),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result)->toBe($hunks);
});

test('aligns simple table columns', function () {
    $hunks = [
        new Hunk('', 1, 4, 1, 4, [
            new DiffLine('context', '| Name | Description | Notes |', 1, 1),
            new DiffLine('context', '|---|---|---|', 2, 2),
            new DiffLine('context', '| a | A longer description | n |', 3, 3),
            new DiffLine('context', '| longer name | b | some notes here |', 4, 4),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    // All columns padded to widest cell in each column
    expect($result[0]->lines[0]->content)->toBe('| Name        | Description          | Notes           |');
    expect($result[0]->lines[1]->content)->toMatch('/^\| -+ \| -+ \| -+ \|$/');
    expect($result[0]->lines[2]->content)->toBe('| a           | A longer description | n               |');
    expect($result[0]->lines[3]->content)->toBe('| longer name | b                    | some notes here |');
});

test('handles mixed diff types in table group', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '| Col | Value |', 1, 1),
            new DiffLine('context', '|---|---|', 2, 2),
            new DiffLine('remove', '| short | x |', 3, null),
            new DiffLine('add', '| a much longer cell | updated |', null, 3),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'docs.md');

    $lines = $result[0]->lines;
    // All lines aligned to the widest content across all types
    expect($lines[0]->content)->toBe('| Col                | Value   |');
    expect($lines[2]->content)->toBe('| short              | x       |');
    expect($lines[2]->type)->toBe('remove');
    expect($lines[3]->content)->toBe('| a much longer cell | updated |');
    expect($lines[3]->type)->toBe('add');
});

test('extends separator row dashes to match column width', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '| Header One | H2 |', 1, 1),
            new DiffLine('context', '|---|---|', 2, 2),
            new DiffLine('context', '| data | d |', 3, 3),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result[0]->lines[1]->content)->toBe('| ---------- | --- |');
});

test('preserves separator alignment markers', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '| Left Longer | Center Longer | Right Longer |', 1, 1),
            new DiffLine('context', '|:---|:---:|---:|', 2, 2),
            new DiffLine('context', '| data | data | data |', 3, 3),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    $sep = $result[0]->lines[1]->content;
    // Left-aligned: starts with :
    expect($sep)->toContain(':----');
    // Center-aligned: starts and ends with :
    expect($sep)->toMatch('/:---+:/');
    // Right-aligned: ends with :
    expect($sep)->toMatch('/---+: \|$/');
});

test('handles tables with different column counts', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine('context', '| a | b | c |', 1, 1),
            new DiffLine('add', '| x | y |', null, 2),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    // All columns padded to minimum width of 3 (separator minimum)
    $lines = $result[0]->lines;
    expect($lines[0]->content)->toBe('| a   | b   | c   |');
    expect($lines[1]->content)->toBe('| x   | y   |     |');
});

test('preserves indented tables', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '    | Col | Value |', 1, 1),
            new DiffLine('context', '    |---|---|', 2, 2),
            new DiffLine('context', '    | longer | x |', 3, 3),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result[0]->lines[0]->content)->toStartWith('    |');
    expect($result[0]->lines[2]->content)->toStartWith('    |');
    // Column alignment should work within the indented table
    expect($result[0]->lines[0]->content)->toBe('    | Col    | Value |');
    expect($result[0]->lines[2]->content)->toBe('    | longer | x     |');
});

test('does not align non-table pipe lines', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine('context', 'echo foo | grep bar', 1, 1),
            new DiffLine('context', 'cat file | wc -l', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result)->toBe($hunks);
});

test('handles multiple table groups in one hunk', function () {
    $hunks = [
        new Hunk('', 1, 7, 1, 7, [
            new DiffLine('context', '| a | b |', 1, 1),
            new DiffLine('context', '|---|---|', 2, 2),
            new DiffLine('context', '| longer | x |', 3, 3),
            new DiffLine('context', '', 4, 4),
            new DiffLine('context', '| c | d |', 5, 5),
            new DiffLine('context', '|---|---|', 6, 6),
            new DiffLine('context', '| y | much longer |', 7, 7),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    // First table: "longer" is widest in col 0, min width 3 for col 1
    expect($result[0]->lines[0]->content)->toBe('| a      | b   |');
    expect($result[0]->lines[2]->content)->toBe('| longer | x   |');

    // Second table: "y" and "c" in col 0, "much longer" and "d" in col 1
    expect($result[0]->lines[4]->content)->toBe('| c   | d           |');
    expect($result[0]->lines[6]->content)->toBe('| y   | much longer |');
});

test('handles empty hunks', function () {
    $result = $this->aligner->alignTables([], 'readme.md');

    expect($result)->toBe([]);
});

test('processes mdx files', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '| Short | Value |', 1, 1),
            new DiffLine('context', '|---|---|', 2, 2),
            new DiffLine('context', '| a longer name | x |', 3, 3),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'docs/page.mdx');

    expect($result[0]->lines[0]->content)->toBe('| Short         | Value |');
    expect($result[0]->lines[2]->content)->toBe('| a longer name | x     |');
});

test('preserves line numbers and types', function () {
    $hunks = [
        new Hunk('', 10, 3, 10, 3, [
            new DiffLine('context', '| a | b |', 10, 10),
            new DiffLine('context', '|---|---|', 11, 11),
            new DiffLine('add', '| longer | x |', null, 12),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result[0]->lines[0]->oldLineNum)->toBe(10)
        ->and($result[0]->lines[0]->newLineNum)->toBe(10)
        ->and($result[0]->lines[2]->type)->toBe('add')
        ->and($result[0]->lines[2]->oldLineNum)->toBeNull()
        ->and($result[0]->lines[2]->newLineNum)->toBe(12);
});
