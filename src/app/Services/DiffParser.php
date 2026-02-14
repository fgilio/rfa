<?php

namespace App\Services;

use App\DTOs\DiffLine;
use App\DTOs\FileDiff;
use App\DTOs\Hunk;

class DiffParser
{
    public function parseSingle(string $rawDiff): ?FileDiff
    {
        return $this->parse($rawDiff)[0] ?? null;
    }

    /**
     * @return FileDiff[]
     */
    public function parse(string $rawDiff): array
    {
        if (trim($rawDiff) === '') {
            return [];
        }

        $files = [];
        $fileSections = preg_split('/^(?=diff --git )/m', $rawDiff);

        foreach ($fileSections as $section) {
            $section = trim($section);
            if ($section === '' || ! str_starts_with($section, 'diff --git ')) {
                continue;
            }

            $file = $this->parseFileSection($section);
            if ($file !== null) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function parseFileSection(string $section): ?FileDiff
    {
        $lines = explode("\n", $section);
        $headerLine = $lines[0]; // diff --git a/path b/path

        // Extract file path from header
        if (! preg_match('#^diff --git [a-z]/(.+?) [a-z]/(.+)$#', $headerLine, $m)) {
            return null;
        }

        $oldPath = $m[1];
        $newPath = $m[2];

        // Detect status from subsequent header lines
        $status = 'modified';
        $isBinary = false;
        $headerEnd = 0;

        for ($i = 1; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (str_starts_with($line, 'new file mode')) {
                $status = 'added';
            } elseif (str_starts_with($line, 'deleted file mode')) {
                $status = 'deleted';
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
            );
        }

        // Parse hunks
        $hunks = [];
        $additions = 0;
        $deletions = 0;
        $hunkContent = '';
        $inHunk = false;

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
                            if ($dl->type === 'add') {
                                $additions++;
                            }
                            if ($dl->type === 'remove') {
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
                    if ($dl->type === 'add') {
                        $additions++;
                    }
                    if ($dl->type === 'remove') {
                        $deletions++;
                    }
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
            if ($raw === '\ No newline at end of file') {
                continue;
            }

            if ($raw === '') {
                // Empty context line (trailing newlines in diff)
                $diffLines[] = new DiffLine('context', '', $oldLine, $newLine);
                $oldLine++;
                $newLine++;

                continue;
            }

            $prefix = $raw[0];
            $content = substr($raw, 1);

            match ($prefix) {
                '+' => (function () use (&$diffLines, $content, &$newLine) {
                    $diffLines[] = new DiffLine('add', $content, null, $newLine);
                    $newLine++;
                })(),
                '-' => (function () use (&$diffLines, $content, &$oldLine) {
                    $diffLines[] = new DiffLine('remove', $content, $oldLine, null);
                    $oldLine++;
                })(),
                default => (function () use (&$diffLines, $content, &$oldLine, &$newLine) {
                    $diffLines[] = new DiffLine('context', $content, $oldLine, $newLine);
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
}
