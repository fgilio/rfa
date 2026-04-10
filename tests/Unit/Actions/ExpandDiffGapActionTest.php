<?php

use App\Actions\ExpandDiffGapAction;
use App\Console\Benchmark\DiffFixtureFactory;

function buildFullContextLines(int $totalLines): array
{
    $lines = [];
    for ($i = 1; $i <= $totalLines; $i++) {
        $lines[] = [
            'type' => 'context',
            'content' => "    // line {$i}",
            'oldLineNum' => $i,
            'newLineNum' => $i,
            'highlightedContent' => "// line {$i}",
        ];
    }

    return $lines;
}

// -- Partial middle gap --

test('partial middle gap expansion preserves separate hunks', function () {
    // 2-hunk fixture: hunk 0 (lines 1-8), hunk 1 (lines 29-36), gap = 20 lines (9-28)
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');
    $fullDiffLines = buildFullContextLines(36);

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 1,
        fullDiffLines: $fullDiffLines,
        lineCount: 15,
    );

    expect($result)->toHaveCount(2);
    expect($result[0]['newCount'])->toBe($diffData['hunks'][0]['newCount'] + 15);

    $remainingGap = $result[1]['newStart'] - ($result[0]['newStart'] + $result[0]['newCount']);
    expect($remainingGap)->toBe(5);

    // Lines 9-23 (top of gap) appended to hunk 0
    $originalLineCount = count($diffData['hunks'][0]['lines']);
    expect($result[0]['lines'][$originalLineCount]['newLineNum'])->toBe(9);
    expect($result[0]['lines'][$originalLineCount + 14]['newLineNum'])->toBe(23);
});

// -- Full middle gap --

test('full middle gap expansion merges hunks into one', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');
    $fullDiffLines = buildFullContextLines(36);

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 1,
        fullDiffLines: $fullDiffLines,
    );

    expect($result)->toHaveCount(1);

    $newLineNums = array_column($result[0]['lines'], 'newLineNum');
    foreach (range(9, 28) as $expected) {
        expect($newLineNums)->toContain($expected);
    }
});

// -- Partial leading gap --

test('partial leading gap expansion shrinks the gap', function () {
    // Hunk starts at line 25, so 24-line leading gap
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['hunks'][0]['newStart'] = 25;
    $diffData['hunks'][0]['oldStart'] = 25;

    $fullDiffLines = buildFullContextLines(40);

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 0,
        fullDiffLines: $fullDiffLines,
        lineCount: 15,
    );

    expect($result[0]['newStart'])->toBe(10)
        ->and($result[0]['newCount'])->toBe($diffData['hunks'][0]['newCount'] + 15);

    // Lines 10-24 (bottom of gap, closest to hunk) prepended
    expect($result[0]['lines'][0]['newLineNum'])->toBe(10);
    expect($result[0]['lines'][14]['newLineNum'])->toBe(24);
});

// -- Partial trailing gap --

test('partial trailing gap expansion appends to last hunk', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['newFileLineCount'] = 50;

    $fullDiffLines = buildFullContextLines(50);
    $originalNewCount = $diffData['hunks'][0]['newCount'];

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 1,
        fullDiffLines: $fullDiffLines,
        lineCount: 15,
        newFileLineCount: 50,
    );

    expect($result[0]['newCount'])->toBe($originalNewCount + 15);

    $remainingGap = 50 - ($result[0]['newStart'] + $result[0]['newCount'] - 1);
    expect($remainingGap)->toBe(27);

    // Lines 9-23 (top of gap) appended
    $originalLineCount = count($diffData['hunks'][0]['lines']);
    expect($result[0]['lines'][$originalLineCount]['newLineNum'])->toBe(9);
    expect($result[0]['lines'][$originalLineCount + 14]['newLineNum'])->toBe(23);
});

// -- Edge cases --

test('returns hunks unchanged when gap is empty', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 1, path: 'src/Test.php');
    $diffData['hunks'][0]['newStart'] = 1; // No leading gap

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 0,
        fullDiffLines: buildFullContextLines(10),
    );

    expect($result)->toBe($diffData['hunks']);
});

test('returns hunks unchanged when full diff has no matching lines', function () {
    $diffData = DiffFixtureFactory::diffData(hunks: 2, path: 'src/Test.php');

    $result = app(ExpandDiffGapAction::class)->handle(
        hunks: $diffData['hunks'],
        hunkIndex: 1,
        fullDiffLines: [], // empty
    );

    expect($result)->toBe($diffData['hunks']);
});
