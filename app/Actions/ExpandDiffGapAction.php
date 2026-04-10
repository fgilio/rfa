<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ExpandDiffGapAction
{
    /**
     * Expand a gap between diff hunks by inserting lines from the full-context diff.
     *
     * @param  list<array{header: string, oldStart: int, oldCount: int, newStart: int, newCount: int, lines: list<array>}>  $hunks
     * @param  int  $hunkIndex  0 = leading gap, count($hunks) = trailing gap (sentinel, not an actual index), else middle gap before hunks[$hunkIndex]
     * @param  list<array{type: string, newLineNum: ?int, oldLineNum: ?int, content: string, highlightedContent: string}>  $fullDiffLines  All lines from the full-context diff's single hunk
     * @param  ?int  $lineCount  Lines to expand (null = full gap)
     * @param  ?int  $newFileLineCount  Total lines in new file (needed for trailing gap boundary)
     * @return list<array> Modified hunks
     */
    public function handle(
        array $hunks,
        int $hunkIndex,
        array $fullDiffLines,
        ?int $lineCount = null,
        ?int $newFileLineCount = null,
    ): array {
        if (empty($hunks)) {
            return $hunks;
        }

        // hunkIndex past the last hunk is a sentinel for the trailing gap
        $isTrailing = $hunkIndex === count($hunks);

        if ($isTrailing) {
            $last = $hunks[count($hunks) - 1];
            $gapNewStart = $last['newStart'] + $last['newCount'];
            $gapNewEnd = $newFileLineCount ?? $gapNewStart;
        } elseif ($hunkIndex === 0) {
            $gapNewStart = 1;
            $gapNewEnd = $hunks[0]['newStart'] - 1;
        } else {
            $prev = $hunks[$hunkIndex - 1];
            $gapNewStart = $prev['newStart'] + $prev['newCount'];
            $gapNewEnd = $hunks[$hunkIndex]['newStart'] - 1;
        }

        if ($gapNewStart > $gapNewEnd) {
            return $hunks;
        }

        // Narrow range for partial expansion
        $totalGapSize = $gapNewEnd - $gapNewStart + 1;
        $isPartial = $lineCount !== null && $lineCount < $totalGapSize;

        if ($isPartial) {
            if ($hunkIndex === 0) {
                // Leading: expand bottom of gap (closest to first hunk)
                $gapNewStart = $gapNewEnd - $lineCount + 1;
            } else {
                // Middle or trailing: expand top of gap (closest to prev/last hunk)
                $gapNewEnd = $gapNewStart + $lineCount - 1;
            }
        }

        // Extract gap lines from the full diff by newLineNum
        $gapLines = collect($fullDiffLines)
            ->filter(fn (array $line): bool => ($line['newLineNum'] ?? null) !== null
                && $line['newLineNum'] >= $gapNewStart
                && $line['newLineNum'] <= $gapNewEnd
                && $line['type'] === 'context')
            ->values()
            ->all();

        if (empty($gapLines)) {
            return $hunks;
        }

        $gapSize = count($gapLines);

        if ($isTrailing) {
            $lastIdx = count($hunks) - 1;
            $hunks[$lastIdx]['lines'] = array_merge($hunks[$lastIdx]['lines'], $gapLines);
            $hunks[$lastIdx]['oldCount'] += $gapSize;
            $hunks[$lastIdx]['newCount'] += $gapSize;
        } elseif ($hunkIndex === 0) {
            $hunks[0]['lines'] = array_merge($gapLines, $hunks[0]['lines']);
            $hunks[0]['oldStart'] -= $gapSize;
            $hunks[0]['oldCount'] += $gapSize;
            $hunks[0]['newStart'] -= $gapSize;
            $hunks[0]['newCount'] += $gapSize;
        } elseif ($isPartial) {
            // Partial middle: append to prev hunk, leave current for remaining gap
            $hunks[$hunkIndex - 1]['lines'] = array_merge($hunks[$hunkIndex - 1]['lines'], $gapLines);
            $hunks[$hunkIndex - 1]['oldCount'] += $gapSize;
            $hunks[$hunkIndex - 1]['newCount'] += $gapSize;
        } else {
            // Full middle: merge prev + gap + current into one hunk
            $prev = $hunks[$hunkIndex - 1];
            $curr = $hunks[$hunkIndex];

            $merged = [
                'header' => $prev['header'],
                'oldStart' => $prev['oldStart'],
                'oldCount' => $prev['oldCount'] + $gapSize + $curr['oldCount'],
                'newStart' => $prev['newStart'],
                'newCount' => $prev['newCount'] + $gapSize + $curr['newCount'],
                'lines' => array_merge($prev['lines'], $gapLines, $curr['lines']),
            ];

            array_splice($hunks, $hunkIndex - 1, 2, [$merged]);
        }

        return $hunks;
    }
}
