<?php

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Services\MarkdownRegionService;

beforeEach(function () {
    $this->service = new MarkdownRegionService;
});

function mdHunk(array $lines): Hunk
{
    $diffLines = [];
    $newLine = 1;
    $oldLine = 1;
    foreach ($lines as $spec) {
        [$type, $content] = $spec;
        $diffLines[] = new DiffLine(
            type: $type,
            content: $content,
            oldLineNum: $type === 'add' ? null : $oldLine++,
            newLineNum: $type === 'remove' ? null : $newLine++,
        );
    }

    return new Hunk(header: '', oldStart: 1, oldCount: count($lines), newStart: 1, newCount: count($lines), lines: $diffLines);
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
        ['context', '# Top'],
        ['context', 'intro'],
        ['context', '## Sub'],
        ['context', 'detail'],
        ['context', '### Sub-sub'],
        ['context', 'leaf'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[0]->headingAncestors)->toBe([])
        ->and($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingLevel)->toBe(2)
        ->and($lines[2]->headingAncestors)->toBe([1])
        ->and($lines[3]->headingAncestors)->toBe([1, 2])
        ->and($lines[4]->headingAncestors)->toBe([1, 2])
        ->and($lines[5]->headingAncestors)->toBe([1, 2, 3]);
});

test('same-level heading closes the previous section', function () {
    $hunk = mdHunk([
        ['context', '## A'],
        ['context', 'a-body'],
        ['context', '## B'],
        ['context', 'b-body'],
    ]);

    $result = $this->service->annotate([$hunk], 'doc.md');
    $lines = $result[0]->lines;

    expect($lines[1]->headingAncestors)->toBe([1])
        ->and($lines[2]->headingId)->toBe(2)
        ->and($lines[2]->headingAncestors)->toBe([])
        ->and($lines[3]->headingAncestors)->toBe([2]);
});

test('higher-level heading closes deeper nested sections', function () {
    $hunk = mdHunk([
        ['context', '# Top'],
        ['context', '## Sub'],
        ['context', '### Deep'],
        ['context', '## Another Sub'],
        ['context', 'tail'],
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

test('remove lines inherit current ancestors but do not open headings', function () {
    $hunk = mdHunk([
        ['context', '# Top'],
        ['remove', '## Was-a-heading'],
        ['context', 'tail'],
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
        ['context', '# Top'],
        ['context', 'intro'],
    ]);
    $hunkB = mdHunk([
        ['context', 'tail'],
        ['context', '## Sub'],
        ['context', 'leaf'],
    ]);

    $result = $this->service->annotate([$hunkA, $hunkB], 'doc.md');

    expect($result[1]->lines[0]->headingAncestors)->toBe([1])
        ->and($result[1]->lines[1]->headingLevel)->toBe(2)
        ->and($result[1]->lines[1]->headingAncestors)->toBe([1])
        ->and($result[1]->lines[2]->headingAncestors)->toBe([1, 2]);
});

test('preserves existing line fields when annotating', function () {
    $line = new DiffLine(
        type: 'context',
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
        ->and($annotated->headingLevel)->toBe(1);
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
