<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Services\SyntaxHighlightService;

beforeEach(function () {
    $this->service = new SyntaxHighlightService;
});

test('highlights PHP hunks', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '<?php', 1, 1),
            new DiffLine('remove', 'echo "old";', 2, null),
            new DiffLine('add', 'echo "new";', null, 2),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[1]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[2]->highlightedContent)->not->toBeNull();

    expect($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('returns unmodified hunks for unknown grammar', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine('add', 'hello', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'file.xyz');

    expect($result)->toBe($hunks)
        ->and($result[0]->lines[0]->highlightedContent)->toBeNull();
});

test('handles empty hunks', function () {
    $result = $this->service->highlightHunks([], 'test.php');

    expect($result)->toBe([]);
});

test('context lines get new-side highlighting', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine('context', '$x = 1;', 1, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('removed lines get old-side highlighting', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 0, [
            new DiffLine('remove', '$old = true;', 1, null),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($result[0]->lines[0]->highlightedContent)->toContain('<span');
});

test('preserves original content field', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine('add', 'echo "hello";', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0]->lines[0]->content)->toBe('echo "hello";');
});

test('highlights mixed add/remove/context hunk with asymmetric sides', function () {
    $hunks = [
        new Hunk('@@ -1,4 +1,3 @@', 1, 4, 1, 3, [
            new DiffLine('context', '<?php', 1, 1),
            new DiffLine('remove', '$a = 1;', 2, null),
            new DiffLine('remove', '$b = 2;', 3, null),
            new DiffLine('add', '$c = 3;', null, 2),
            new DiffLine('context', 'return true;', 4, 3),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    foreach ($result[0]->lines as $line) {
        expect($line->highlightedContent)->not->toBeNull();
    }

    expect($result[0]->lines[1]->content)->toBe('$a = 1;')
        ->and($result[0]->lines[3]->content)->toBe('$c = 3;');
});

test('handles hunk with only context lines', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '<?php', 1, 1),
            new DiffLine('context', '', 2, 2),
            new DiffLine('context', 'return true;', 3, 3),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    foreach ($result[0]->lines as $line) {
        expect($line->highlightedContent)->not->toBeNull();
    }
});

test('returns Hunk DTOs not arrays', function () {
    $hunks = [
        new Hunk('', 1, 1, 1, 1, [
            new DiffLine('add', '$x = 1;', null, 1),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    expect($result[0])->toBeInstanceOf(Hunk::class)
        ->and($result[0]->lines[0])->toBeInstanceOf(DiffLine::class);
});
