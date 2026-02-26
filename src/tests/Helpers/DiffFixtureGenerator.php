<?php

declare(strict_types=1);

namespace Tests\Helpers;

use App\DTOs\FileListEntry;

final class DiffFixtureGenerator
{
    private static array $directories = [
        'src/Controllers', 'src/Models', 'src/Services', 'src/Actions',
        'app/Http', 'app/Jobs', 'app/Events', 'app/Listeners',
        'resources/views', 'config', 'database/migrations', 'tests/Unit',
    ];

    private static array $extensions = ['php', 'blade.php', 'js', 'ts', 'vue', 'css'];

    private static array $statuses = ['modified', 'added', 'deleted', 'modified', 'modified'];

    public static function fileEntry(
        string $path = 'src/Example.php',
        string $status = 'modified',
        int $additions = 10,
        int $deletions = 3,
    ): array {
        return (new FileListEntry(
            path: $path,
            status: $status,
            oldPath: null,
            additions: $additions,
            deletions: $deletions,
            isBinary: false,
            isUntracked: false,
        ))->toArray();
    }

    /** @return array<int, array<string, mixed>> */
    public static function fileEntries(int $count): array
    {
        $entries = [];

        for ($i = 0; $i < $count; $i++) {
            $dir = self::$directories[$i % count(self::$directories)];
            $ext = self::$extensions[$i % count(self::$extensions)];
            $status = self::$statuses[$i % count(self::$statuses)];
            $path = "{$dir}/File{$i}.{$ext}";

            $entries[] = self::fileEntry(
                path: $path,
                status: $status,
                additions: ($i * 7 + 3) % 50,
                deletions: ($i * 3 + 1) % 20,
            );
        }

        return $entries;
    }

    /**
     * @return array{path: string, status: string, oldPath: ?string, hunks: array, additions: int, deletions: int, isBinary: bool, tooLarge: bool}
     */
    public static function diffData(
        int $hunks = 1,
        int $linesPerHunk = 10,
        string $path = 'src/Example.php',
    ): array {
        $hunkData = [];
        $totalAdditions = 0;
        $totalDeletions = 0;
        $currentOldLine = 1;
        $currentNewLine = 1;

        for ($h = 0; $h < $hunks; $h++) {
            $lines = [];
            $hunkAdditions = 0;
            $hunkDeletions = 0;

            for ($l = 0; $l < $linesPerHunk; $l++) {
                $mod = $l % 5;

                if ($mod === 0) {
                    // remove line
                    $lines[] = [
                        'type' => 'remove',
                        'content' => "    \$old_var_{$h}_{$l} = getValue();",
                        'oldLineNum' => $currentOldLine++,
                        'newLineNum' => null,
                        'highlightedContent' => "<span class=\"hl-variable\">\$old_var_{$h}_{$l}</span> = getValue();",
                    ];
                    $hunkDeletions++;
                } elseif ($mod === 1) {
                    // add line
                    $lines[] = [
                        'type' => 'add',
                        'content' => "    \$new_var_{$h}_{$l} = getUpdatedValue();",
                        'oldLineNum' => null,
                        'newLineNum' => $currentNewLine++,
                        'highlightedContent' => "<span class=\"hl-variable\">\$new_var_{$h}_{$l}</span> = getUpdatedValue();",
                    ];
                    $hunkAdditions++;
                } else {
                    // context line
                    $lines[] = [
                        'type' => 'context',
                        'content' => "    // context line {$h}:{$l}",
                        'oldLineNum' => $currentOldLine++,
                        'newLineNum' => $currentNewLine++,
                        'highlightedContent' => "<span class=\"hl-comment\">// context line {$h}:{$l}</span>",
                    ];
                }
            }

            $oldCount = $hunkDeletions + ($linesPerHunk - $hunkAdditions - $hunkDeletions);
            $newCount = $hunkAdditions + ($linesPerHunk - $hunkAdditions - $hunkDeletions);

            $hunkData[] = [
                'header' => "@@ -{$currentOldLine},{$oldCount} +{$currentNewLine},{$newCount} @@",
                'oldStart' => $currentOldLine - $oldCount,
                'oldCount' => $oldCount,
                'newStart' => $currentNewLine - $newCount,
                'newCount' => $newCount,
                'lines' => $lines,
            ];

            $totalAdditions += $hunkAdditions;
            $totalDeletions += $hunkDeletions;

            // gap between hunks
            $currentOldLine += 20;
            $currentNewLine += 20;
        }

        return [
            'path' => $path,
            'status' => 'modified',
            'oldPath' => null,
            'hunks' => $hunkData,
            'additions' => $totalAdditions,
            'deletions' => $totalDeletions,
            'isBinary' => false,
            'tooLarge' => false,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function comments(string $fileId, int $count): array
    {
        $comments = [];

        for ($i = 0; $i < $count; $i++) {
            $side = $i % 2 === 0 ? 'right' : 'left';
            $line = ($i + 1) * 5;

            $comments[] = [
                'id' => 'comment-'.hash('xxh128', "{$fileId}-{$i}"),
                'fileId' => $fileId,
                'file' => 'src/Example.php',
                'side' => $side,
                'startLine' => $line,
                'endLine' => $line,
                'body' => "Review comment #{$i}: Consider refactoring this section for clarity.",
            ];
        }

        return $comments;
    }
}
