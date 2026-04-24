<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;

class MarkdownRegionService
{
    private const MARKDOWN_EXTENSIONS = ['md', 'mdx', 'markdown'];

    /**
     * Annotate diff lines with ATX heading metadata so the UI can fold regions.
     *
     * Only lines on the new side (context + add) influence heading state and
     * fenced-code tracking; remove lines inherit the current ancestor chain so
     * they fold alongside their surrounding context.
     *
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function annotate(array $hunks, string $filePath): array
    {
        if (! $this->isMarkdown($filePath)) {
            return $hunks;
        }

        /** @var array<int, array{id: int, level: int}> $stack */
        $stack = [];
        $inFence = false;
        $nextId = 1;

        $result = [];
        foreach ($hunks as $hunk) {
            $newLines = [];
            foreach ($hunk->lines as $line) {
                $isNewSide = $line->type !== 'remove';
                $fenceLine = $isNewSide && $this->isFenceLine($line->content);

                if ($fenceLine) {
                    $inFence = ! $inFence;
                    $newLines[] = $this->annotateLine($line, null, null, $this->ancestorIds($stack));

                    continue;
                }

                $headingLevel = (! $inFence && $isNewSide)
                    ? $this->headingLevel($line->content)
                    : null;

                if ($headingLevel !== null) {
                    while ($stack !== [] && end($stack)['level'] >= $headingLevel) {
                        array_pop($stack);
                    }
                    $ancestors = $this->ancestorIds($stack);
                    $id = $nextId++;
                    $stack[] = ['id' => $id, 'level' => $headingLevel];
                    $newLines[] = $this->annotateLine($line, $headingLevel, $id, $ancestors);
                } else {
                    $newLines[] = $this->annotateLine($line, null, null, $this->ancestorIds($stack));
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

    private function isMarkdown(string $filePath): bool
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return in_array($ext, self::MARKDOWN_EXTENSIONS, true);
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
     * Fenced code block delimiter: ``` or ~~~ (3+ of either), up to 3 leading spaces.
     */
    private function isFenceLine(string $content): bool
    {
        return (bool) preg_match('/^ {0,3}(`{3,}|~{3,})/', $content);
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
