<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Services\CsvAlignerService;

beforeEach(function () {
    $this->aligner = new CsvAlignerService;
});

test('returns hunks unchanged for non-csv files', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine(LineType::Context, 'a,b,c', 1, 1),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'file.php');

    expect($result)->toBe($hunks);
});

test('aligns simple csv columns', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, 'name,age,city', 1, 1),
            new DiffLine(LineType::Context, 'Alice,30,NYC', 2, 2),
            new DiffLine(LineType::Context, 'Bob,25,Los Angeles', 3, 3),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'data.csv');

    expect($result[0]->lines[0]->content)->toBe('name ,age,city');
    expect($result[0]->lines[1]->content)->toBe('Alice,30 ,NYC');
    expect($result[0]->lines[2]->content)->toBe('Bob  ,25 ,Los Angeles');
});

test('handles mixed diff types in group', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine(LineType::Context, 'col,value', 1, 1),
            new DiffLine(LineType::Remove, 'short,x', 2, null),
            new DiffLine(LineType::Add, 'a much longer cell,updated', null, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'docs.csv');

    $lines = $result[0]->lines;
    expect($lines[0]->content)->toBe('col               ,value');
    expect($lines[1]->content)->toBe('short             ,x');
    expect($lines[1]->type)->toBe(LineType::Remove);
    expect($lines[2]->content)->toBe('a much longer cell,updated');
    expect($lines[2]->type)->toBe(LineType::Add);
});

test('does not pad the last column', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, 'a,b', 1, 1),
            new DiffLine(LineType::Context, 'longer,c', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'x.csv');

    expect($result[0]->lines[0]->content)->toBe('a     ,b');
    expect($result[0]->lines[1]->content)->toBe('longer,c');
});

test('preserves quoted fields containing commas', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, '"Smith, John",30,NYC', 1, 1),
            new DiffLine(LineType::Context, 'Alice,25,LA', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'people.csv');

    expect($result[0]->lines[0]->content)->toBe('"Smith, John",30,NYC');
    expect($result[0]->lines[1]->content)->toBe('Alice        ,25,LA');
});

test('preserves escaped double quotes inside quoted fields', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, '"he said ""hi""",x', 1, 1),
            new DiffLine(LineType::Context, 'short,y', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'quotes.csv');

    expect($result[0]->lines[0]->content)->toBe('"he said ""hi""",x');
    expect($result[0]->lines[1]->content)->toBe('short           ,y');
});

test('skips alignment when a line has an unterminated quote', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, '"starts here', 1, 1),
            new DiffLine(LineType::Context, 'continues",x', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'multiline.csv');

    expect($result)->toBe($hunks);
});

test('skips alignment for single-column csv', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, 'apple', 1, 1),
            new DiffLine(LineType::Context, 'banana', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'one.csv');

    expect($result)->toBe($hunks);
});

test('handles rows with different column counts', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, 'a,b,c', 1, 1),
            new DiffLine(LineType::Add, 'x,y', null, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'r.csv');

    $lines = $result[0]->lines;
    expect($lines[0]->content)->toBe('a,b,c');
    expect($lines[1]->content)->toBe('x,y');
});

test('handles multiple groups separated by blank lines', function () {
    $hunks = [
        new Hunk('', 1, 5, 1, 5, [
            new DiffLine(LineType::Context, 'a,b', 1, 1),
            new DiffLine(LineType::Context, 'longer,x', 2, 2),
            new DiffLine(LineType::Context, '', 3, 3),
            new DiffLine(LineType::Context, 'c,d', 4, 4),
            new DiffLine(LineType::Context, 'y,much longer', 5, 5),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'two.csv');

    expect($result[0]->lines[0]->content)->toBe('a     ,b');
    expect($result[0]->lines[1]->content)->toBe('longer,x');
    expect($result[0]->lines[2]->content)->toBe('');
    expect($result[0]->lines[3]->content)->toBe('c,d');
    expect($result[0]->lines[4]->content)->toBe('y,much longer');
});

test('handles empty hunks', function () {
    $result = $this->aligner->alignRows([], 'data.csv');

    expect($result)->toBe([]);
});

test('preserves line numbers and types', function () {
    $hunks = [
        new Hunk('', 10, 2, 10, 2, [
            new DiffLine(LineType::Context, 'a,b', 10, 10),
            new DiffLine(LineType::Add, 'longer,x', null, 11),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'data.csv');

    expect($result[0]->lines[0]->oldLineNum)->toBe(10)
        ->and($result[0]->lines[0]->newLineNum)->toBe(10)
        ->and($result[0]->lines[1]->type)->toBe(LineType::Add)
        ->and($result[0]->lines[1]->oldLineNum)->toBeNull()
        ->and($result[0]->lines[1]->newLineNum)->toBe(11);
});

test('returns hunks unchanged when all columns already aligned', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, 'aaa,bbb', 1, 1),
            new DiffLine(LineType::Context, 'xxx,yyy', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'aligned.csv');

    expect($result)->toBe($hunks);
});

test('handles multi-byte characters using display width', function () {
    $hunks = [
        new Hunk('', 1, 2, 1, 2, [
            new DiffLine(LineType::Context, 'name,city', 1, 1),
            new DiffLine(LineType::Context, '日本,Tokyo', 2, 2),
        ]),
    ];

    $result = $this->aligner->alignRows($hunks, 'mb.csv');

    expect($result[0]->lines[0]->content)->toBe('name,city');
    expect($result[0]->lines[1]->content)->toBe('日本,Tokyo');
});
