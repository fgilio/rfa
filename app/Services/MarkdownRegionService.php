<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Support\MarkdownPath;

class MarkdownRegionService
{
    /**
     * Annotate diff lines with ATX heading metadata so the UI can fold regions.
     *
     * Only lines on the new side (context + add) influence heading state and
     * fenced-code tracking; remove lines inherit the current ancestor chain so
     * they fold alongside their surrounding context.
     *
     * Heading ids are the new-side line number so fold state remains stable
     * across diff recomputes (e.g. expanding context).
     *
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function annotate(array $hunks, string $filePath): array
    {
        if (! MarkdownPath::isMarkdown($filePath)) {
            return $hunks;
        }

        /** @var array<int, array{id: int, level: int}> $stack */
        $stack = [];
        /** @var array{char: string, length: int}|null $openFence */
        $openFence = null;

        $result = [];
        foreach ($hunks as $hunk) {
            $newLines = [];
            foreach ($hunk->lines as $line) {
                $isNewSide = $line->type !== LineType::Remove;
                $fence = $isNewSide ? $this->fenceMarker($line->content) : null;

                if ($fence !== null) {
                    if ($openFence === null) {
                        $openFence = $fence;
                    } elseif ($fence['char'] === $openFence['char'] && $fence['length'] >= $openFence['length']) {
                        $openFence = null;
                    }
                    $newLines[] = $this->withAncestors($line, $this->ancestorIds($stack));

                    continue;
                }

                $headingLevel = ($openFence === null && $isNewSide)
                    ? $this->headingLevel($line->content)
                    : null;

                if ($headingLevel !== null && $line->newLineNum !== null) {
                    while ($stack !== [] && end($stack)['level'] >= $headingLevel) {
                        array_pop($stack);
                    }
                    $ancestors = $this->ancestorIds($stack);
                    $id = $line->newLineNum;
                    $stack[] = ['id' => $id, 'level' => $headingLevel];
                    $newLines[] = $this->annotateLine($line, $headingLevel, $id, $ancestors);
                } else {
                    $newLines[] = $this->withAncestors($line, $this->ancestorIds($stack));
                }
            }

            $result[] = new Hunk(
                header: $hunk->header,
                oldStart: $hunk->oldStart,
                oldCount: $hunk->oldCount,
                newStart: $hunk->newStart,
                newCount: $hunk->newCount,
                lines: $newLines,
            );
        }

        return $result;
    }

    /**
     * ATX heading: 1-6 '#' followed by a space and some text.
     * Allows up to 3 leading spaces per CommonMark.
     */
    private function headingLevel(string $content): ?int
    {
        if (! preg_match('/^ {0,3}(#{1,6})\s+\S/', $content, $m)) {
            return null;
        }

        return strlen($m[1]);
    }

    /**
     * Detect a fenced code block delimiter (``` or ~~~, 3+ chars, up to 3 leading spaces).
     *
     * @return array{char: string, length: int}|null
     */
    private function fenceMarker(string $content): ?array
    {
        if (! preg_match('/^ {0,3}((`{3,})|(~{3,}))/', $content, $m)) {
            return null;
        }

        return [
            'char' => $m[1][0],
            'length' => strlen($m[1]),
        ];
    }

    /**
     * @param  array<int, array{id: int, level: int}>  $stack
     * @return int[]
     */
    private function ancestorIds(array $stack): array
    {
        return array_map(fn (array $frame): int => $frame['id'], $stack);
    }

    /**
     * Avoid reconstructing the DiffLine when there is nothing to attach.
     *
     * @param  int[]  $ancestors
     */
    private function withAncestors(DiffLine $line, array $ancestors): DiffLine
    {
        if ($ancestors === []) {
            return $line;
        }

        return $this->annotateLine($line, null, null, $ancestors);
    }

    /**
     * @param  int[]  $ancestors
     */
    private function annotateLine(DiffLine $line, ?int $headingLevel, ?int $headingId, array $ancestors): DiffLine
    {
        return new DiffLine(
            type: $line->type,
            content: $line->content,
            oldLineNum: $line->oldLineNum,
            newLineNum: $line->newLineNum,
            highlightedContent: $line->highlightedContent,
            headingLevel: $headingLevel,
            headingId: $headingId,
            headingAncestors: $ancestors,
        );
    }
}
