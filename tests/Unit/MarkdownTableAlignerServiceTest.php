<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Services\MarkdownTableAlignerService;

beforeEach(function () {
    $this->aligner = new MarkdownTableAlignerService;
});

test('returns hunks unchanged for non-markdown files', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine(LineType::Context, '| col1 | col2 |', 1, 1),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'file.php');

    expect($result)->toBe($hunks);
});

test('returns hunks unchanged when no tables present', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine(LineType::Context, 'just some text', 1, 1),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    expect($result)->toBe($hunks);
});

test('attaches grid cell metadata to each table row', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| Name | Description |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| a | a longer description |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    expect($lines[0]->table)->toMatchArray([
        'separator' => false,
        'header' => true,
        'cells' => ['Name', 'Description'],
    ]);
    // Every row in the group shares the same column template, so they align.
    expect($lines[0]->table['template'])->toBe($lines[2]->table['template'])
        ->and($lines[0]->table['template'])->toContain('minmax(')
        ->and($lines[0]->table['maxWidth'])->toBeInt();

    // The separator row collapses to a header rule, not cells.
    expect($lines[1]->table)->toBe(['separator' => true]);

    // The body row is not a header and carries its own cells.
    expect($lines[2]->table)->toMatchArray([
        'separator' => false,
        'header' => false,
        'cells' => ['a', 'a longer description'],
    ]);
});

test('renders a changed separator as aligned cells instead of a rule', function () {
    $hunks = [
        new Hunk('', 1, 4, 1, 4, [
            new DiffLine(LineType::Context, '| Name | Default |', 1, 1),
            new DiffLine(LineType::Remove, '| --- | --- |', 2, null),
            new DiffLine(LineType::Add, '| :--- | ---: |', null, 2),
            new DiffLine(LineType::Context, '| a | b |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // The removed separator keeps its `---` markers on the shared column grid.
    expect($lines[1]->table)->toMatchArray([
        'separator' => true,
        'cells' => ['---', '---'],
    ])
        ->and($lines[1]->table['template'])->toBe($lines[0]->table['template'])
        ->and($lines[1]->table['maxWidth'])->toBe($lines[0]->table['maxWidth']);

    // The added separator shows the new alignment markers, aligned the same way.
    expect($lines[2]->table)->toMatchArray([
        'separator' => true,
        'cells' => [':---', '---:'],
    ])
        ->and($lines[2]->table['template'])->toBe($lines[0]->table['template']);
});

test('keeps an unchanged separator as a thin rule', function () {
    $hunks = [
        new Hunk('', 1, 4, 1, 4, [
            new DiffLine(LineType::Context, '| Name | Default |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Remove, '| a | old |', 3, null),
            new DiffLine(LineType::Add, '| a | new |', null, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // An unchanged (context) separator carries no diff signal, so it collapses
    // to a rule with no cells regardless of body-cell changes around it.
    expect($lines[1]->table)->toBe(['separator' => true]);
});

test('a changed separator does not inflate its column widths', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| A | B |', 1, 1),
            new DiffLine(LineType::Add, '| :--------------- | ---------------: |', null, 2),
            new DiffLine(LineType::Context, '| x | y |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // The long dash runs in the separator must not set the column weights —
    // those still come from the (short) body/header cells.
    expect($lines[0]->table['template'])->toBe('minmax(min(6ch,50%),6fr) minmax(min(6ch,50%),6fr)');
});

test('leaves source content untouched', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| Name | Description |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| a | longer |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // No padding is written into the content — the grid handles alignment.
    expect($lines[0]->content)->toBe('| Name | Description |')
        ->and($lines[2]->content)->toBe('| a | longer |');
});

test('keeps an escaped pipe inside a single cell', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| a \| b | second column |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| short | val |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'doc.md')[0]->lines;

    // The literal pipe (\|) stays inside cell 1 rather than shattering it into two.
    expect($lines[0]->table['cells'])->toBe(['a \| b', 'second column']);
});

test('does not annotate a pipe block without a separator row', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, '| just | some |', 1, 1),
            new DiffLine(LineType::Context, '| pipe | lines |', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignTables($hunks, 'readme.md');

    // No GFM separator -> not a table -> left as plain source.
    expect($result)->toBe($hunks);
});

test('annotates mixed diff types in a table group and preserves types', function () {
    $hunks = [
        new Hunk('', 1, 4, 1, 4, [
            new DiffLine(LineType::Context, '| Col | Value |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Remove, '| short | x |', 3, null),
            new DiffLine(LineType::Add, '| a much longer cell | updated |', null, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'docs.md')[0]->lines;

    expect($lines[2]->type)->toBe(LineType::Remove)
        ->and($lines[2]->table['cells'])->toBe(['short', 'x'])
        ->and($lines[3]->type)->toBe(LineType::Add)
        ->and($lines[3]->table['cells'])->toBe(['a much longer cell', 'updated'])
        // Shared template keeps both sides aligned to the widest column.
        ->and($lines[2]->table['template'])->toBe($lines[3]->table['template']);
});

test('caps a prose column so it does not starve its neighbours', function () {
    $prose = str_repeat('word ', 60);
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| Item | Why it bites |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, "| composer.lock | {$prose} |", 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // 'composer.lock' (13) keeps its width; the long prose column is capped at 60.
    // Both tracks carry the cell padding and slack on top of their text width,
    // and both floors stop at the 14ch shrink limit — so when the table is wider
    // than the space it has, the prose column gives way instead of every column
    // shrinking in step and crushing the narrow one.
    expect($lines[0]->table['template'])->toBe('minmax(min(14ch,50%),16fr) minmax(min(14ch,50%),63fr)');
});

test('marks every header row before the separator', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| H1 | H2 |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| body | row |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    expect($lines[0]->table['header'])->toBeTrue()
        ->and($lines[2]->table['header'])->toBeFalse();
});

test('handles multiple table groups in one hunk', function () {
    $hunks = [
        new Hunk('', 1, 7, 1, 7, [
            new DiffLine(LineType::Context, '| a | b |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| longer | x |', 3, 3),
            new DiffLine(LineType::Context, '', 4, 4),
            new DiffLine(LineType::Context, '| c | d |', 5, 5),
            new DiffLine(LineType::Context, '| --- | --- |', 6, 6),
            new DiffLine(LineType::Context, '| y | much longer |', 7, 7),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    expect($lines[0]->table['cells'])->toBe(['a', 'b'])
        ->and($lines[3]->table)->toBeNull()
        ->and($lines[4]->table['cells'])->toBe(['c', 'd'])
        ->and($lines[6]->table['cells'])->toBe(['y', 'much longer']);
});

test('handles empty hunks', function () {
    $result = $this->aligner->alignTables([], 'readme.md');

    expect($result)->toBe([]);
});

test('processes mdx files', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| Short | Value |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| a longer name | x |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'docs/page.mdx')[0]->lines;

    expect($lines[2]->table['cells'])->toBe(['a longer name', 'x']);
});

test('preserves line numbers and types', function () {
    $hunks = [
        new Hunk('', 10, 3, 10, 3, [
            new DiffLine(LineType::Context, '| a | b |', 10, 10),
            new DiffLine(LineType::Context, '| --- | --- |', 11, 11),
            new DiffLine(LineType::Add, '| longer | x |', null, 12),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    expect($lines[0]->oldLineNum)->toBe(10)
        ->and($lines[0]->newLineNum)->toBe(10)
        ->and($lines[2]->type)->toBe(LineType::Add)
        ->and($lines[2]->oldLineNum)->toBeNull()
        ->and($lines[2]->newLineNum)->toBe(12);
});

test('preserves highlighting and heading metadata already on the line', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(
                type: LineType::Add,
                content: '| a | b |',
                oldLineNum: null,
                newLineNum: 1,
                highlightedContent: '<span>| a | b |</span>',
                headingAncestors: [5],
            ),
            new DiffLine(LineType::Add, '| --- | --- |', null, 2),
        ]),
    ];

    $line = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines[0];

    expect($line->highlightedContent)->toBe('<span>| a | b |</span>')
        ->and($line->headingAncestors)->toBe([5])
        ->and($line->table['cells'])->toBe(['a', 'b']);
});

test('budgets cell padding into the track and the shared max width', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, '| Evaluador | Estado |', 1, 1),
            new DiffLine(LineType::Context, '| --- | --- |', 2, 2),
            new DiffLine(LineType::Context, '| Juan Pablo Locatelli | completed |', 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // Text widths are 20 and 9; each track adds the 2ch of cell padding plus 1ch
    // of slack, and the max width is the sum — so the table is never squeezed
    // below its content.
    expect($lines[0]->table['template'])->toBe('minmax(min(14ch,50%),23fr) minmax(min(12ch,50%),12fr)')
        ->and($lines[0]->table['maxWidth'])->toBe(35);
});

test('bounds the column floors so a wide table cannot overflow its pane', function () {
    $row = '|'.str_repeat(' a |', 7);
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, $row, 1, 1),
            new DiffLine(LineType::Context, '|'.str_repeat(' --- |', 7), 2, 2),
            new DiffLine(LineType::Context, $row, 3, 3),
        ]),
    ];

    $lines = $this->aligner->alignTables($hunks, 'readme.md')[0]->lines;

    // Seven 6ch floors would demand 42ch of a pane that may not have it, and the
    // grid has no scroll container to absorb the excess. Each floor is also held
    // to a seventh of the pane, so the shares total 100% at worst.
    $floors = [];
    preg_match_all('/min\((\d+)ch,([\d.]+)%\)/', $lines[0]->table['template'], $floors);

    expect($floors[1])->toBe(array_fill(0, 7, '6'))
        ->and(array_sum(array_map('floatval', $floors[2])))->toBeLessThanOrEqual(100.0);
});
