<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Services\MarkdownRegionService;

beforeEach(function () {
    $this->service = new MarkdownRegionService;
});

function mdHunk(array $lines, int $startNewLine = 1, int $startOldLine = 1): Hunk
{
    $diffLines = [];
    $newLine = $startNewLine;
    $oldLine = $startOldLine;
    foreach ($lines as $spec) {
        [$type, $content] = $spec;
        $lineType = LineType::from($type);
        $diffLines[] = new DiffLine(
            type: $lineType,
            content: $content,
            oldLineNum: $lineType === LineType::Add ? null : $oldLine++,
            newLineNum: $lineType === LineType::Remove ? null : $newLine++,
        );
    }

    return new Hunk(
        header: '',
        oldStart: $startOldLine,
        oldCount: count($lines),
        newStart: $startNewLine,
        newCount: count($lines),
        lines: $diffLines,
    );
}

test('returns hunks unchanged for non-markdown files', function () {
    $hunk = mdHunk([
        ['context', '# Heading'],
        ['context', 'body'],
    ]);

    $result = $this->service->annotate([$hunk], 'file.php');

    expect($result[0]->lines[0]->headingLevel)->toBeNull()
        ->and($result[0]->lines[0]->headingAncestors)->toBe([]);
});

test('detects a single ATX heading and assigns ancestors to following lines', function () {
    $hunk = mdHunk([
        ['context', '# Title'],
        ['context', 'paragraph text'],
        ['add', 'new paragraph'],
    ]);

    $result = $this->service->annotate([$hunk], 'README.md');
    $lines = $result[0]->lines;

    expect($lines[0]->headingLevel)->toBe(1)
        ->and($lines[0]->headingId)->toBe(1)
        ->and($lines[0]->headingAncestors)->toBe([])
        ->and($lines[1]->headingLevel)->toBeNull()
        ->and($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingAncestors)->toBe([1]);
});

test('nests headings so deeper levels inherit outer ancestors', function () {
    $hunk = mdHunk([
        ['context', '# Top'],          // newLine 1
        ['context', 'intro'],           // newLine 2
        ['context', '## Sub'],          // newLine 3
        ['context', 'detail'],          // newLine 4
        ['context', '### Sub-sub'],     // newLine 5
        ['context', 'leaf'],            // newLine 6
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[0]->headingAncestors)->toBe([])
        ->and($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingLevel)->toBe(2)
        ->and($lines[2]->headingId)->toBe(3)
        ->and($lines[2]->headingAncestors)->toBe([1])
        ->and($lines[3]->headingAncestors)->toBe([1, 3])
        ->and($lines[4]->headingAncestors)->toBe([1, 3])
        ->and($lines[4]->headingId)->toBe(5)
        ->and($lines[5]->headingAncestors)->toBe([1, 3, 5]);
});

test('same-level heading closes the previous section', function () {
    $hunk = mdHunk([
        ['context', '## A'],       // newLine 1
        ['context', 'a-body'],      // newLine 2
        ['context', '## B'],        // newLine 3
        ['context', 'b-body'],      // newLine 4
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingId)->toBe(3)
        ->and($lines[2]->headingAncestors)->toBe([])
        ->and($lines[3]->headingAncestors)->toBe([3]);
});

test('higher-level heading closes deeper nested sections', function () {
    $hunk = mdHunk([
        ['context', '# Top'],           // newLine 1
        ['context', '## Sub'],          // newLine 2
        ['context', '### Deep'],        // newLine 3
        ['context', '## Another Sub'],  // newLine 4
        ['context', 'tail'],            // newLine 5
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[3]->headingLevel)->toBe(2)
        ->and($lines[3]->headingAncestors)->toBe([1])
        ->and($lines[4]->headingAncestors)->toBe([1, 4]);
});

test('lines inside a fenced code block are not treated as headings', function () {
    $hunk = mdHunk([
        ['context', '# Real'],
        ['context', '```'],
        ['context', '# not a heading'],
        ['context', '## also not'],
        ['context', '```'],
        ['context', 'after fence'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[2]->headingLevel)->toBeNull()
        ->and($lines[3]->headingLevel)->toBeNull()
        ->and($lines[5]->headingAncestors)->toBe([1]);
});

test('tilde fences also suppress heading detection', function () {
    $hunk = mdHunk([
        ['context', '# Real'],
        ['context', '~~~'],
        ['context', '## inside'],
        ['context', '~~~'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');

    expect($result[0]->lines[2]->headingLevel)->toBeNull();
});

test('mismatched fence marker does not close the open fence', function () {
    $hunk = mdHunk([
        ['context', '```'],              // opens backtick fence
        ['context', '~~~'],              // tilde line - should NOT close
        ['context', '## still inside'],  // must stay inside code block
        ['context', '```'],              // closes backtick fence
        ['context', '## now a heading'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[2]->headingLevel)->toBeNull()
        ->and($lines[4]->headingLevel)->toBe(2);
});

test('closing fence must be at least as long as the opening fence', function () {
    $hunk = mdHunk([
        ['context', '````'],             // opens with 4 backticks
        ['context', '```'],              // 3 backticks - too short to close
        ['context', '## still inside'],
        ['context', '````'],             // 4 backticks - closes
        ['context', '## now a heading'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[2]->headingLevel)->toBeNull()
        ->and($lines[4]->headingLevel)->toBe(2);
});

test('closing fence may be longer than the opening fence', function () {
    $hunk = mdHunk([
        ['context', '```'],               // opens with 3 backticks
        ['context', '``````'],            // 6 backticks - valid close
        ['context', '## now a heading'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');

    expect($result[0]->lines[2]->headingLevel)->toBe(2);
});

test('remove lines inherit current ancestors but do not open headings', function () {
    $hunk = mdHunk([
        ['context', '# Top'],           // newLine 1
        ['remove', '## Was-a-heading'], // no newLine
        ['context', 'tail'],            // newLine 2
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[1]->headingLevel)->toBeNull()
        ->and($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingAncestors)->toBe([1]);
});

test('lines that look heading-like but lack trailing text are ignored', function () {
    $hunk = mdHunk([
        ['context', '#no-space'],
        ['context', '#'],
        ['context', '#   '],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');

    foreach ($result[0]->lines as $line) {
        expect($line->headingLevel)->toBeNull();
    }
});

test('heading state carries across hunks in the same file', function () {
    $hunkA = mdHunk([
        ['context', '# Top'],      // newLine 1
        ['context', 'intro'],       // newLine 2
    ]);
    $hunkB = mdHunk([
        ['context', 'tail'],        // newLine 10
        ['context', '## Sub'],      // newLine 11
        ['context', 'leaf'],        // newLine 12
    ], startNewLine: 10);

    $result = $this->service->annotate([$hunkA, $hunkB], 'doc.md');

    expect($result[1]->lines[0]->headingAncestors)->toBe([1])
        ->and($result[1]->lines[1]->headingLevel)->toBe(2)
        ->and($result[1]->lines[1]->headingId)->toBe(11)
        ->and($result[1]->lines[1]->headingAncestors)->toBe([1])
        ->and($result[1]->lines[2]->headingAncestors)->toBe([1, 11]);
});

test('heading ids are stable across recomputes (driven by new line number)', function () {
    // Smaller hunk: only the section around '## Sub' is visible.
    $small = mdHunk([
        ['context', '## Sub'],     // newLine 5
        ['context', 'body'],        // newLine 6
    ], startNewLine: 5);

    // Expanded hunk: the file intro now fits in the diff too.
    $large = mdHunk([
        ['context', '# Top'],       // newLine 1
        ['context', 'intro'],        // newLine 2
        ['context', ''],             // newLine 3
        ['context', ''],             // newLine 4
        ['context', '## Sub'],       // newLine 5
        ['context', 'body'],         // newLine 6
    ]);

    $smallResult = $this->service->annotate([$small], 'doc.md');
    $largeResult = $this->service->annotate([$large], 'doc.md');

    expect($smallResult[0]->lines[0]->headingId)->toBe(5)
        ->and($largeResult[0]->lines[4]->headingId)->toBe(5);
});

test('preserves existing line fields when annotating', function () {
    $line = new DiffLine(
        type: LineType::Context,
        content: '# Title',
        oldLineNum: 3,
        newLineNum: 3,
        highlightedContent: '<span>highlighted</span>',
    );
    $hunk = new Hunk('', 1, 1, 1, 1, [$line]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $annotated = $result[0]->lines[0];

    expect($annotated->content)->toBe('# Title')
        ->and($annotated->oldLineNum)->toBe(3)
        ->and($annotated->newLineNum)->toBe(3)
        ->and($annotated->highlightedContent)->toBe('<span>highlighted</span>')
        ->and($annotated->headingLevel)->toBe(1)
        ->and($annotated->headingId)->toBe(3);
});

test('recognises mdx and markdown extensions', function (string $path) {
    $hunk = mdHunk([['context', '# H']]);

    $result = $this->service->annotate([$hunk], $path);

    expect($result[0]->lines[0]->headingLevel)->toBe(1);
})->with(['doc.md', 'doc.mdx', 'doc.markdown', 'README.MD']);

test('toArray emits heading fields only when set', function () {
    $hunk = mdHunk([
        ['context', '# Top'],
        ['context', 'body'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $headingArr = $result[0]->lines[0]->toArray();
    $bodyArr = $result[0]->lines[1]->toArray();

    expect($headingArr)->toHaveKeys(['headingLevel', 'headingId'])
        ->and($headingArr)->not->toHaveKey('headingAncestors')
        ->and($headingArr['headingLevel'])->toBe(1)
        ->and($bodyArr)->not->toHaveKey('headingLevel')
        ->and($bodyArr['headingAncestors'])->toBe([1]);
});
