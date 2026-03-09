<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use App\DTOs\FileListEntry;

final class DiffFixtureFactory
{
    private static array $directories = [
        'src/Controllers',
        'src/Models',
        'src/Services',
        'src/Actions',
        'app/Http',
        'app/Jobs',
        'app/Events',
        'app/Listeners',
        'resources/views',
        'config',
        'database/migrations',
        'tests/Unit',
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
            $extension = self::$extensions[$i % count(self::$extensions)];
            $status = self::$statuses[$i % count(self::$statuses)];
            $path = "{$dir}/File{$i}.{$extension}";

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
     * @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool}
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

        for ($hunkIndex = 0; $hunkIndex < $hunks; $hunkIndex++) {
            $lines = [];
            $hunkAdditions = 0;
            $hunkDeletions = 0;

            for ($lineIndex = 0; $lineIndex < $linesPerHunk; $lineIndex++) {
                $mod = $lineIndex % 5;

                if ($mod === 0) {
                    $lines[] = [
                        'type' => 'remove',
                        'content' => "    \$old_var_{$hunkIndex}_{$lineIndex} = getValue();",
                        'oldLineNum' => $currentOldLine++,
                        'newLineNum' => null,
                        'highlightedContent' => "<span class=\"hl-variable\">\$old_var_{$hunkIndex}_{$lineIndex}</span> = getValue();",
                    ];
                    $hunkDeletions++;

                    continue;
                }

                if ($mod === 1) {
                    $lines[] = [
                        'type' => 'add',
                        'content' => "    \$new_var_{$hunkIndex}_{$lineIndex} = getUpdatedValue();",
                        'oldLineNum' => null,
                        'newLineNum' => $currentNewLine++,
                        'highlightedContent' => "<span class=\"hl-variable\">\$new_var_{$hunkIndex}_{$lineIndex}</span> = getUpdatedValue();",
                    ];
                    $hunkAdditions++;

                    continue;
                }

                $lines[] = [
                    'type' => 'context',
                    'content' => "    // context line {$hunkIndex}:{$lineIndex}",
                    'oldLineNum' => $currentOldLine++,
                    'newLineNum' => $currentNewLine++,
                    'highlightedContent' => "<span class=\"hl-comment\">// context line {$hunkIndex}:{$lineIndex}</span>",
                ];
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
