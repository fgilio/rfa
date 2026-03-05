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

// -- Multi-hunk --

test('multi-hunk diff highlights all hunks independently', function () {
    // Hunk 1: class declaration area with comment additions
    // Hunk 2: inside method body, far from hunk 1
    // Ensures each hunk is tokenized in isolation - concatenating lines
    // from distant hunks would break the tokenizer's grammar state
    $hunks = [
        new Hunk('@@ -5,3 +5,6 @@', 5, 3, 5, 6, [
            new DiffLine('context', 'use Illuminate\Support\Str;', 5, 5),
            new DiffLine('add', '// A comment block', null, 6),
            new DiffLine('add', '// that spans lines', null, 7),
            new DiffLine('context', '', 6, 8),
            new DiffLine('context', 'class Example {', 7, 9),
        ]),
        new Hunk('@@ -20,3 +23,3 @@', 20, 3, 23, 3, [
            new DiffLine('context', '    public function run(): void {', 20, 23),
            new DiffLine('remove', '        $old = true;', 21, null),
            new DiffLine('add', '        foreach ($items as $i) {', null, 24),
        ]),
    ];

    $result = $this->service->highlightHunks($hunks, 'test.php');

    // Every non-empty line in both hunks must be highlighted
    foreach ($result as $hunk) {
        foreach ($hunk->lines as $line) {
            expect($line->highlightedContent)->not->toBeNull();
            if ($line->content !== '') {
                expect($line->highlightedContent)->toContain('<span');
            }
        }
    }

    // Hunk 2's foreach keyword must have a distinct color (not default text)
    // This would fail if the tokenizer state from hunk 1 bled into hunk 2
    $h2add = $result[1]->lines[2]->highlightedContent;
    expect($h2add)->toContain('foreach');

    preg_match_all('/<span\s/', $h2add, $spans);
    expect(count($spans[0]))->toBeGreaterThan(2, 'foreach line should have multiple styled tokens');
});

test('dark theme produces different colors than light', function () {
    $hunks = [
        new Hunk('', 1, 0, 1, 1, [
            new DiffLine('add', 'echo "hello";', null, 1),
        ]),
    ];

    $lightResult = $this->service->highlightHunks($hunks, 'test.php', 'light');
    $darkResult = $this->service->highlightHunks($hunks, 'test.php', 'dark');

    expect($lightResult[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($darkResult[0]->lines[0]->highlightedContent)->not->toBeNull()
        ->and($lightResult[0]->lines[0]->highlightedContent)
        ->not->toBe($darkResult[0]->lines[0]->highlightedContent);
});

test('output does not contain phiki dark CSS variables', function () {
    $hunks = [
        new Hunk('', 1, 3, 1, 3, [
            new DiffLine('context', '<?php', 1, 1),
            new DiffLine('remove', 'echo "old";', 2, null),
            new DiffLine('add', 'echo "new";', null, 2),
        ]),
    ];

    $lightResult = $this->service->highlightHunks($hunks, 'test.php', 'light');
    $darkResult = $this->service->highlightHunks($hunks, 'test.php', 'dark');

    foreach ([$lightResult, $darkResult] as $result) {
        foreach ($result[0]->lines as $line) {
            if ($line->highlightedContent !== null) {
                expect($line->highlightedContent)->not->toContain('--phiki-dark');
            }
        }
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
