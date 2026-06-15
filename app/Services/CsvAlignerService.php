<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Support\CsvPath;

class CsvAlignerService
{
    /**
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function alignRows(array $hunks, string $filePath): array
    {
        if (! CsvPath::isCsv($filePath)) {
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
            if ($this->isCsvLine($lines[$i]->content)) {
                $groupStart = $i;
                while ($i < $count && $this->isCsvLine($lines[$i]->content)) {
                    $i++;
                }

                $aligned = $this->alignGroup(array_slice($lines, $groupStart, $i - $groupStart));

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

    private function isCsvLine(string $content): bool
    {
        return trim($content) !== '';
    }

    /**
     * @param  DiffLine[]  $group
     * @return DiffLine[]
     */
    private function alignGroup(array $group): array
    {
        $parsed = [];
        $maxCols = 0;

        foreach ($group as $line) {
            $row = $this->parseCells($line->content);

            if ($row['unterminatedQuote']) {
                return $group;
            }

            $parsed[] = $row;
            $maxCols = max($maxCols, count($row['cells']));
        }

        if ($maxCols <= 1) {
            return $group;
        }

        $colWidths = array_fill(0, $maxCols, 0);
        foreach ($parsed as $row) {
            foreach ($row['cells'] as $colIndex => $cell) {
                $colWidths[$colIndex] = max($colWidths[$colIndex], mb_strwidth($cell));
            }
        }

        $result = [];
        foreach ($group as $i => $line) {
            $row = $parsed[$i];
            $cellCount = count($row['cells']);
            $paddedCells = [];

            foreach ($row['cells'] as $colIndex => $cell) {
                $isLast = $colIndex === $cellCount - 1;
                $paddedCells[] = $isLast ? $cell : $this->padCell($cell, $colWidths[$colIndex]);
            }

            $newContent = implode(',', $paddedCells);

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
     * @return array{cells: string[], unterminatedQuote: bool}
     */
    private function parseCells(string $content): array
    {
        $cells = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($content);

        for ($i = 0; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '"') {
                if ($inQuotes && $i + 1 < $length && $content[$i + 1] === '"') {
                    $current .= '""';
                    $i++;

                    continue;
                }

                $inQuotes = ! $inQuotes;
                $current .= '"';

                continue;
            }

            if ($char === ',' && ! $inQuotes) {
                $cells[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $cells[] = $current;

        return [
            'cells' => $cells,
            'unterminatedQuote' => $inQuotes,
        ];
    }

    private function padCell(string $cell, int $width): string
    {
        $currentWidth = mb_strwidth($cell);
        $padding = max(0, $width - $currentWidth);

        return $cell.str_repeat(' ', $padding);
    }
}
