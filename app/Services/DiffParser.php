<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Support\AnsiText;

class DiffParser
{
    private const SYMLINK_MODE = '120000';

    private const MOVED_OLD_MARKER = "\0rfa-moved-old\0";

    private const MOVED_NEW_MARKER = "\0rfa-moved-new\0";

    public function __construct(
        private readonly PatchNormalizerService $patchNormalizer = new PatchNormalizerService,
    ) {}

    public function parseSingle(string $rawDiff, bool $detectMovedLines = false): ?FileDiff
    {
        return $this->parse($rawDiff, $detectMovedLines)[0] ?? null;
    }

    /**
     * @return FileDiff[]
     */
    public function parse(string $rawDiff, bool $detectMovedLines = false): array
    {
        if ($detectMovedLines) {
            $rawDiff = $this->stripAnsiAndMarkMovedLines($rawDiff);
        }

        $rawDiff = $this->patchNormalizer->normalize($rawDiff);

        if (trim($rawDiff) === '') {
            return [];
        }

        $fileSections = preg_split('/^(?=diff --git )/m', $rawDiff);
        if ($fileSections === false) {
            return [];
        }

        return collect($fileSections)
            ->map(fn (string $section): string => trim($section))
            ->filter(fn (string $section): bool => $section !== '' && str_starts_with($section, 'diff --git '))
            ->map(fn (string $section): ?FileDiff => $this->parseFileSection($section))
            ->filter()
            ->values()
            ->all();
    }

    private function parseFileSection(string $section): ?FileDiff
    {
        $lines = explode("\n", $section);
        $headerLine = $lines[0]; // diff --git a/path b/path

        // The normalizer owns header-path extraction: its validated split
        // handles paths containing spaces (even ` b/`) that a lazy regex
        // would cut at the wrong boundary.
        $paths = $this->patchNormalizer->headerPaths($headerLine);
        if ($paths === null) {
            return null;
        }

        [$oldPath, $newPath] = $paths;

        // A rename or copy packs two distinct paths onto the `diff --git` line,
        // where git leaves spaces unquoted, so that line cannot be split back
        // apart reliably. The `rename`/`copy` markers carry each path alone and
        // properly quoted, so they are the authoritative source when present.
        $renamePaths = $this->patchNormalizer->renamePaths($lines);
        if ($renamePaths !== null) {
            [$oldPath, $newPath] = $renamePaths;
        }

        // Detect status from subsequent header lines
        $status = 'modified';
        $isBinary = false;
        $isSymlink = false;
        $headerEnd = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (str_starts_with($line, 'new file mode')) {
                $status = 'added';
                $isSymlink = str_ends_with($line, self::SYMLINK_MODE);
            } elseif (str_starts_with($line, 'deleted file mode')) {
                $status = 'deleted';
                $isSymlink = str_ends_with($line, self::SYMLINK_MODE);
            } elseif (str_starts_with($line, 'index ') && str_ends_with($line, self::SYMLINK_MODE)) {
                $isSymlink = true;
            } elseif (str_starts_with($line, 'rename from')) {
                $status = 'renamed';
            } elseif (str_starts_with($line, 'similarity index')) {
                $status = 'renamed';
            } elseif (str_starts_with($line, 'Binary files')) {
                $isBinary = true;
                $status = $status === 'modified' ? 'binary' : $status;
            } elseif (str_starts_with($line, '--- ') || str_starts_with($line, '@@ ')) {
                $headerEnd = $i;
                break;
            }
        }

        if ($isBinary) {
            return new FileDiff(
                path: $newPath,
                status: $status,
                oldPath: $oldPath !== $newPath ? $oldPath : null,
                hunks: [],
                additions: 0,
                deletions: 0,
                isBinary: true,
                isSymlink: $isSymlink,
            );
        }

        // Parse hunks
        $hunks = [];
        $additions = 0;
        $deletions = 0;
        // Find where hunks start (after --- and +++ lines)
        $hunkStartIndex = $headerEnd;
        for ($i = $headerEnd; $i < count($lines); $i++) {
            if (str_starts_with($lines[$i], '@@ ')) {
                $hunkStartIndex = $i;
                break;
            }
        }

        // Collect and parse hunks
        $currentHunkLines = [];
        $currentHunkHeader = '';

        for ($i = $hunkStartIndex; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (str_starts_with($line, '@@ ')) {
                // Save previous hunk
                if ($currentHunkHeader !== '') {
                    $hunk = $this->parseHunk($currentHunkHeader, $currentHunkLines);
                    if ($hunk !== null) {
                        $hunks[] = $hunk;
                        foreach ($hunk->lines as $dl) {
                            if ($dl->type === LineType::Add) {
                                $additions++;
                            }
                            if ($dl->type === LineType::Remove) {
                                $deletions++;
                            }
                        }
                    }
                }
                $currentHunkHeader = $line;
                $currentHunkLines = [];
            } else {
                $currentHunkLines[] = $line;
            }
        }

        // Save last hunk
        if ($currentHunkHeader !== '') {
            $hunk = $this->parseHunk($currentHunkHeader, $currentHunkLines);
            if ($hunk !== null) {
                $hunks[] = $hunk;
                foreach ($hunk->lines as $dl) {
                    if ($dl->type === LineType::Add) {
                        $additions++;
                    }
                    if ($dl->type === LineType::Remove) {
                        $deletions++;
                    }
                }
            }
        }

        // Extract symlink target from diff content
        $symlinkTarget = null;
        if ($isSymlink && ! empty($hunks)) {
            foreach ($hunks[0]->lines as $dl) {
                if ($dl->type === LineType::Add) {
                    $symlinkTarget = $dl->content;
                    break;
                }
                if ($dl->type === LineType::Remove && $symlinkTarget === null) {
                    $symlinkTarget = $dl->content;
                }
            }
        }

        return new FileDiff(
            path: $newPath,
            status: $status,
            oldPath: $oldPath !== $newPath ? $oldPath : null,
            hunks: $hunks,
            additions: $additions,
            deletions: $deletions,
            isSymlink: $isSymlink,
            symlinkTarget: $symlinkTarget,
        );
    }

    /** @param array<int, string> $rawLines */
    private function parseHunk(string $header, array $rawLines): ?Hunk
    {
        // Parse @@ -old_start,old_count +new_start,new_count @@ optional context
        if (! preg_match('/@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)/', $header, $m)) {
            return null;
        }

        $oldStart = (int) $m[1];
        $oldCount = $m[2] !== '' ? (int) $m[2] : 1;
        $newStart = (int) $m[3];
        $newCount = $m[4] !== '' ? (int) $m[4] : 1;

        $oldLine = $oldStart;
        $newLine = $newStart;
        $diffLines = [];

        foreach ($rawLines as $raw) {
            [$raw, $moved] = $this->takeMovedMarker($raw);

            if ($raw === '\ No newline at end of file') {
                continue;
            }

            if ($raw === '') {
                // Empty context line (trailing newlines in diff)
                $diffLines[] = new DiffLine(LineType::Context, '', $oldLine, $newLine);
                $oldLine++;
                $newLine++;

                continue;
            }

            $prefix = $raw[0];
            $content = substr($raw, 1);

            match ($prefix) {
                '+' => (function () use (&$diffLines, $content, &$newLine, $moved) {
                    $diffLines[] = new DiffLine(
                        type: LineType::Add,
                        content: $content,
                        oldLineNum: null,
                        newLineNum: $newLine,
                        moved: $moved === 'new' ? $moved : null,
                    );
                    $newLine++;
                })(),
                '-' => (function () use (&$diffLines, $content, &$oldLine, $moved) {
                    $diffLines[] = new DiffLine(
                        type: LineType::Remove,
                        content: $content,
                        oldLineNum: $oldLine,
                        newLineNum: null,
                        moved: $moved === 'old' ? $moved : null,
                    );
                    $oldLine++;
                })(),
                default => (function () use (&$diffLines, $content, &$oldLine, &$newLine) {
                    $diffLines[] = new DiffLine(LineType::Context, $content, $oldLine, $newLine);
                    $oldLine++;
                    $newLine++;
                })(),
            };
        }

        return new Hunk(
            header: trim($m[5]),
            oldStart: $oldStart,
            oldCount: $oldCount,
            newStart: $newStart,
            newCount: $newCount,
            lines: $diffLines,
        );
    }

    private function stripAnsiAndMarkMovedLines(string $rawDiff): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $rawDiff));

        return implode("\n", array_map(function (string $line): string {
            $marker = $this->movedMarkerForAnsiLine($line);

            return $marker.AnsiText::strip($line);
        }, $lines));
    }

    private function movedMarkerForAnsiLine(string $line): string
    {
        if (preg_match('/^((?:\x1b\[[0-9;]*m)+)([+-])/', $line, $matches) !== 1) {
            return '';
        }

        preg_match_all('/\x1b\[([0-9;]*)m/', $matches[1], $codeMatches);

        $codes = collect($codeMatches[1])
            ->flatMap(fn (string $sequence): array => array_filter(explode(';', $sequence), fn (string $code): bool => $code !== ''))
            ->map(fn (string $code): int => (int) $code)
            ->all();

        $sign = $matches[2];
        $isFaintMovedLine = in_array(2, $codes, true);

        if ($sign === '-' && ($isFaintMovedLine || $this->hasAnySgrCode($codes, [34, 35]))) {
            return self::MOVED_OLD_MARKER;
        }

        if ($sign === '+' && ($isFaintMovedLine || $this->hasAnySgrCode($codes, [33, 36]))) {
            return self::MOVED_NEW_MARKER;
        }

        return '';
    }

    /**
     * @param  list<int>  $codes
     * @param  list<int>  $expected
     */
    private function hasAnySgrCode(array $codes, array $expected): bool
    {
        return collect($expected)->contains(fn (int $code): bool => in_array($code, $codes, true));
    }

    /**
     * @return array{0: string, 1: 'old'|'new'|null}
     */
    private function takeMovedMarker(string $line): array
    {
        if (str_starts_with($line, self::MOVED_OLD_MARKER)) {
            return [substr($line, strlen(self::MOVED_OLD_MARKER)), 'old'];
        }

        if (str_starts_with($line, self::MOVED_NEW_MARKER)) {
            return [substr($line, strlen(self::MOVED_NEW_MARKER)), 'new'];
        }

        return [$line, null];
    }
}
