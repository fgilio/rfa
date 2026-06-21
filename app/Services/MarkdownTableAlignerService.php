<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Support\MarkdownPath;

class MarkdownTableAlignerService
{
    /**
     * Per-column weight ceiling, in characters. A prose column (paragraph-length
     * cells) is capped here so it shares the available width with its siblings
     * instead of dominating; short label columns keep their natural width.
     */
    private const COLUMN_WEIGHT_CAP = 60;

    /**
     * Floor so a separator column still resolves to a usable track.
     */
    private const COLUMN_WEIGHT_MIN = 3;

    /**
     * Annotate contiguous markdown table rows with the structured cell data and
     * shared column template the diff view needs to lay each row out as a real
     * CSS grid. The source content is left untouched; only `table` metadata is
     * attached. Runs last in the diff pipeline so highlighting and heading
     * annotation are preserved on the rebuilt lines.
     *
     * @param  Hunk[]  $hunks
     * @return Hunk[]
     */
    public function alignTables(array $hunks, string $filePath): array
    {
        if (! MarkdownPath::isMarkdown($filePath)) {
            return $hunks;
        }

        return array_map(fn (Hunk $hunk) => $this->annotateHunk($hunk), $hunks);
    }

    private function annotateHunk(Hunk $hunk): Hunk
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

                $annotated = $this->annotateTableGroup(array_slice($lines, $groupStart, $i - $groupStart));

                foreach ($annotated as $offset => $line) {
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
    private function annotateTableGroup(array $group): array
    {
        $parsed = array_map(fn (DiffLine $line) => $this->parseCells($line->content), $group);

        $firstSeparator = null;
        foreach ($parsed as $index => $row) {
            if ($row['isSeparator']) {
                $firstSeparator = $index;
                break;
            }
        }

        // A pipe-prefixed block without a separator row (`| --- |`) is not a GFM
        // table — leave it as plain source rather than forcing a grid onto it.
        if ($firstSeparator === null || count($group) < 2) {
            return $group;
        }

        $maxCols = max(array_map(fn (array $row) => count($row['cells']), $parsed));

        if ($maxCols === 0) {
            return $group;
        }

        $weights = $this->columnWeights($parsed, $maxCols);
        $template = collect($weights)
            ->map(fn (int $weight) => "minmax(0,{$weight}fr)")
            ->implode(' ');
        $maxWidth = array_sum($weights);

        $result = [];
        foreach ($group as $offset => $line) {
            $row = $parsed[$offset];

            $table = $row['isSeparator']
                ? ['separator' => true]
                : [
                    'separator' => false,
                    'header' => $offset < $firstSeparator,
                    'cells' => $row['cells'],
                    'template' => $template,
                    'maxWidth' => $maxWidth,
                ];

            $result[] = $this->withTable($line, $table);
        }

        return $result;
    }

    /**
     * Column weight is the widest cell in that column, capped so a prose column
     * can't starve its neighbours. Used as the `fr` ratio for the grid track.
     *
     * @param  array<int, array{indent: string, cells: string[], isSeparator: bool}>  $parsed
     * @return int[]
     */
    private function columnWeights(array $parsed, int $maxCols): array
    {
        $widths = array_fill(0, $maxCols, 0);

        foreach ($parsed as $row) {
            if ($row['isSeparator']) {
                continue;
            }
            foreach ($row['cells'] as $colIndex => $cell) {
                $widths[$colIndex] = max($widths[$colIndex], $this->displayWidth($cell));
            }
        }

        return array_map(
            fn (int $width) => max(self::COLUMN_WEIGHT_MIN, min($width, self::COLUMN_WEIGHT_CAP)),
            $widths,
        );
    }

    /**
     * @param  array{separator: true}|array{separator: false, header: bool, cells: string[], template: string, maxWidth: int}  $table
     */
    private function withTable(DiffLine $line, array $table): DiffLine
    {
        return new DiffLine(
            type: $line->type,
            content: $line->content,
            oldLineNum: $line->oldLineNum,
            newLineNum: $line->newLineNum,
            highlightedContent: $line->highlightedContent,
            headingLevel: $line->headingLevel,
            headingId: $line->headingId,
            headingAncestors: $line->headingAncestors,
            moved: $line->moved,
            table: $table,
        );
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

    /**
     * Display width of a cell: an escaped pipe (`\|`) is two source characters but
     * renders as one, so it must count as a single column for alignment.
     */
    private function displayWidth(string $cell): int
    {
        return mb_strwidth(str_replace('\\|', '|', $cell));
    }
}
