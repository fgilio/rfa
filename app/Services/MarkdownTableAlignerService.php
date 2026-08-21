<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
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
     * Horizontal padding a cell adds on top of its text, in characters. Mirrors
     * the `1ch` per side on `.diff-md-td`. Folded into the track so the `fr`
     * ratios describe the full cell box: without it the shared max-width is
     * short by the padding of every column, and the whole table renders squeezed
     * — short labels like `Estado` wrapping to `Esta`/`do`.
     */
    private const COLUMN_PADDING = 2;

    /**
     * Smallest track a column may shrink to when the table is wider than the
     * available space, in characters. `fr` alone shrinks every column by the
     * same ratio, so a narrow label column gets crushed to a few characters to
     * buy width for a prose column that has plenty. A column narrower than this
     * is never shrunk at all; wider ones stop here.
     */
    private const COLUMN_MIN_TRACK = 14;

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
        $maxCols = 0;
        foreach ($parsed as $index => $row) {
            if ($row['isSeparator'] && $firstSeparator === null) {
                $firstSeparator = $index;
            }
            $maxCols = max($maxCols, count($row['cells']));
        }

        // A pipe-prefixed block without a separator row (`| --- |`) is not a GFM
        // table — leave it as plain source rather than forcing a grid onto it.
        if ($firstSeparator === null || count($group) < 2 || $maxCols === 0) {
            return $group;
        }

        $tracks = collect($this->columnWeights($parsed, $maxCols))
            ->map(fn (int $weight) => $weight + self::COLUMN_PADDING);
        $template = $tracks
            ->map(fn (int $track) => sprintf('minmax(%dch,%dfr)', min($track, self::COLUMN_MIN_TRACK), $track))
            ->implode(' ');
        $maxWidth = $tracks->sum();

        $result = [];
        foreach ($group as $offset => $line) {
            $row = $parsed[$offset];

            $table = $row['isSeparator']
                ? $this->separatorTable($line, $row['cells'], $template, $maxWidth)
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
     * Build the table metadata for a separator row.
     *
     * An unchanged separator carries no information for the reader, so it
     * collapses to a thin header rule. A separator that is itself part of the
     * diff (added or removed) instead renders its own `:---`/`---:` cells on the
     * shared column template, so the change in alignment markers is visible
     * rather than flattened to two indistinguishable rules.
     *
     * @param  string[]  $cells
     * @return array{separator: true}|array{separator: true, cells: string[], template: string, maxWidth: int}
     */
    private function separatorTable(DiffLine $line, array $cells, string $template, int $maxWidth): array
    {
        if ($line->type === LineType::Context) {
            return ['separator' => true];
        }

        return [
            'separator' => true,
            'cells' => $cells,
            'template' => $template,
            'maxWidth' => $maxWidth,
        ];
    }

    /**
     * Column weight is the widest cell in that column, capped so a prose column
     * can't starve its neighbours. Padding is added on top to form the track.
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
     * @param  array{separator: true}|array{separator: true, cells: string[], template: string, maxWidth: int}|array{separator: false, header: bool, cells: string[], template: string, maxWidth: int}  $table
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
