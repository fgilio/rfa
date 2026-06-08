<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Support\MarkdownPath;

class MarkdownTableAlignerService
{
    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function alignTables(array $hunks, string $filePath): array
    {
        if (! MarkdownPath::isMarkdown($filePath)) {
            return $hunks;
        }

        return array_map(fn (Hunk $hunk) => $this->alignHunk($hunk), $hunks);
    }

    private function alignHunk(Hunk $hunk): Hunk
    {
        $lines = $hunk->lines;
        $count = count($lines);

        if ($count === 0) {
            return $hunk;
        }

        $modified = false;
        $newLines = $lines;
        $i = 0;

        while ($i < $count) {
            if ($this->isTableLine($lines[$i]->content)) {
                $groupStart = $i;
                while ($i < $count && $this->isTableLine($lines[$i]->content)) {
                    $i++;
                }

                $aligned = $this->alignTableGroup(array_slice($lines, $groupStart, $i - $groupStart));

                foreach ($aligned as $offset => $line) {
                    if ($line !== $lines[$groupStart + $offset]) {
                        $modified = true;
                    }
                    $newLines[$groupStart + $offset] = $line;
                }
            } else {
                $i++;
            }
        }

        if (! $modified) {
            return $hunk;
        }

        return new Hunk(
            header: $hunk->header,
            oldStart: $hunk->oldStart,
            oldCount: $hunk->oldCount,
            newStart: $hunk->newStart,
            newCount: $hunk->newCount,
            lines: $newLines,
        );
    }

    private function isTableLine(string $content): bool
    {
        return (bool) preg_match('/^\s*\|/', $content);
    }

    /**
     * @param  DiffLine[]  $group
     * @return DiffLine[]
     */
    private function alignTableGroup(array $group): array
    {
        $parsed = [];
        $maxCols = 0;

        foreach ($group as $line) {
            $row = $this->parseCells($line->content);
            $parsed[] = $row;
            $maxCols = max($maxCols, count($row['cells']));
        }

        if ($maxCols === 0) {
            return $group;
        }

        // Compute max width per column (excluding separator rows)
        $colWidths = array_fill(0, $maxCols, 0);
        foreach ($parsed as $row) {
            if ($row['isSeparator']) {
                continue;
            }
            foreach ($row['cells'] as $colIndex => $cell) {
                $colWidths[$colIndex] = max($colWidths[$colIndex], $this->displayWidth($cell));
            }
        }

        // Ensure separator rows also contribute a minimum width of 3 (for ---)
        foreach ($colWidths as $colIndex => $width) {
            $colWidths[$colIndex] = max($width, 3);
        }

        // Rebuild each line with padded cells
        $result = [];
        foreach ($group as $i => $line) {
            $row = $parsed[$i];
            $paddedCells = [];

            for ($col = 0; $col < $maxCols; $col++) {
                $cell = $row['cells'][$col] ?? '';

                if ($row['isSeparator']) {
                    $paddedCells[] = $this->padSeparatorCell($cell, $colWidths[$col]);
                } else {
                    $paddedCells[] = $this->padContentCell($cell, $colWidths[$col]);
                }
            }

            $newContent = $row['indent'].'| '.implode(' | ', $paddedCells).' |';

            if ($newContent === $line->content) {
                $result[] = $line;
            } else {
                $result[] = new DiffLine(
                    type: $line->type,
                    content: $newContent,
                    oldLineNum: $line->oldLineNum,
                    newLineNum: $line->newLineNum,
                    moved: $line->moved,
                );
            }
        }

        return $result;
    }

    /**
     * @return array{indent: string, cells: string[], isSeparator: bool}
     */
    private function parseCells(string $content): array
    {
        // Extract leading whitespace
        preg_match('/^(\s*)/', $content, $m);
        $indent = $m[1];
        $trimmed = substr($content, strlen($indent));

        // Strip the leading and trailing row delimiters. The trailing strip uses a
        // negative lookbehind so a final cell ending in an escaped pipe (`\|`) keeps it.
        $trimmed = preg_replace('/^\|/', '', $trimmed);
        $trimmed = preg_replace('/(?<!\\\\)\|$/', '', $trimmed);

        // Split on unescaped pipes only. A cell may legitimately contain `\|`
        // (a literal pipe). Splitting on every `|` would shatter such a cell and
        // inflate the column count, corrupting the whole table group.
        $cells = array_map('trim', preg_split('/(?<!\\\\)\|/', $trimmed) ?: ['']);

        $isSeparator = collect($cells)->every(
            fn (string $cell) => (bool) preg_match('/^:?-+:?$/', $cell)
        );

        return [
            'indent' => $indent,
            'cells' => $cells,
            'isSeparator' => $isSeparator,
        ];
    }

    private function padContentCell(string $cell, int $width): string
    {
        $padding = max(0, $width - $this->displayWidth($cell));

        return $cell.str_repeat(' ', $padding);
    }

    /**
     * Display width of a cell: an escaped pipe (`\|`) is two source characters but
     * renders as one, so it must count as a single column for alignment.
     */
    private function displayWidth(string $cell): int
    {
        return mb_strwidth(str_replace('\\|', '|', $cell));
    }

    private function padSeparatorCell(string $cell, int $width): string
    {
        if ($cell === '') {
            return str_repeat('-', $width);
        }

        // Detect alignment markers
        $leftColon = str_starts_with($cell, ':');
        $rightColon = str_ends_with($cell, ':');

        $dashCount = $width - ($leftColon ? 1 : 0) - ($rightColon ? 1 : 0);
        $dashCount = max(1, $dashCount);

        return ($leftColon ? ':' : '').str_repeat('-', $dashCount).($rightColon ? ':' : '');
    }
}
