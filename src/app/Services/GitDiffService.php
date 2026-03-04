<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class GitDiffService
{
    public function __construct(
        private readonly GitProcessService $git,
        private readonly IgnoreService $ignoreService,
    ) {}

    /** @return FileListEntry[] */
    public function getFileList(string $repoPath, ?string $globalGitignorePath = null, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();
        $excludes = $this->ignoreService->getExcludePathspecs($repoPath);

        // Get status (M/A/D/R) for tracked changes
        $nameStatus = $this->git->run($repoPath, [
            ...$target->toDiffArgs(), '--name-status', '--find-renames',
            '--', '.', ...$excludes,
        ]);

        // Get +/- line counts for tracked changes
        $numstat = $this->git->run($repoPath, [
            ...$target->toDiffArgs(), '--numstat', '--find-renames',
            '--', '.', ...$excludes,
        ]);

        // Parse name-status into [path => [status, oldPath]]
        $statusMap = [];
        foreach (array_filter(explode("\n", trim($nameStatus))) as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $statusCode = $parts[0];
            if (str_starts_with($statusCode, 'R')) {
                $statusMap[$parts[2]] = ['renamed', $parts[1]];
            } elseif ($statusCode === 'A') {
                $statusMap[$parts[1]] = ['added', null];
            } elseif ($statusCode === 'D') {
                $statusMap[$parts[1]] = ['deleted', null];
            } else {
                $statusMap[$parts[1]] = ['modified', null];
            }
        }

        // Parse numstat into [path => [additions, deletions, isBinary]]
        $statMap = [];
        foreach (array_filter(explode("\n", trim($numstat))) as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 3) {
                continue;
            }

            // Binary files show "-" for additions/deletions
            $isBinary = $parts[0] === '-' && $parts[1] === '-';
            // For renames, numstat shows the new path (last tab-separated value)
            $path = $parts[2];
            // Renames show as "new\told" in some formats, handle "{old => new}" too
            if (str_contains($path, ' => ')) {
                // Extract just the new path
                preg_match('/\{.*? => (.*?)\}/', $path, $m);
                if ($m) {
                    $path = str_replace($m[0], $m[1], $path);
                }
            }
            $statMap[$path] = [
                'additions' => $isBinary ? 0 : (int) $parts[0],
                'deletions' => $isBinary ? 0 : (int) $parts[1],
                'isBinary' => $isBinary,
            ];
        }

        $entries = [];

        // Process tracked changes
        foreach ($statusMap as $path => [$status, $oldPath]) {
            $stats = $statMap[$path] ?? ['additions' => 0, 'deletions' => 0, 'isBinary' => false];
            $isBinary = $stats['isBinary'];

            if ($isBinary && $status === 'modified') {
                $status = 'binary';
            }

            $entries[] = new FileListEntry(
                path: $path,
                status: $status,
                oldPath: $oldPath,
                additions: $stats['additions'],
                deletions: $stats['deletions'],
                isBinary: $isBinary,
                isUntracked: false,
                lastModified: $target->isWorkingDirectory() ? $this->getLastModified($repoPath, $path) : null,
            );
        }

        // Get untracked files only when comparing against working tree
        if ($target->isWorkingDirectory()) {
            $lsFilesArgs = ['ls-files', '--others', '--exclude-standard'];
            if ($globalGitignorePath !== null && File::isFile($globalGitignorePath)) {
                $lsFilesArgs[] = '--exclude-from='.$globalGitignorePath;
            }
            $untrackedOutput = $this->git->run($repoPath, $lsFilesArgs);

            if (trim($untrackedOutput) !== '') {
                $untrackedFiles = array_filter(explode("\n", trim($untrackedOutput)));

                foreach ($untrackedFiles as $file) {
                    if ($this->ignoreService->isPathExcluded($file, $excludes)) {
                        continue;
                    }

                    $fullPath = $repoPath.'/'.$file;
                    if (! File::isFile($fullPath)) {
                        continue;
                    }

                    $isBinary = $this->isBinary($fullPath);

                    if ($isBinary) {
                        $entries[] = new FileListEntry(
                            path: $file,
                            status: 'added',
                            oldPath: null,
                            additions: 0,
                            deletions: 0,
                            isBinary: true,
                            isUntracked: true,
                            lastModified: $this->getLastModified($repoPath, $file),
                        );

                        continue;
                    }

                    $content = File::get($fullPath);
                    $lineCount = substr_count($content, "\n") + ($content !== '' && ! str_ends_with($content, "\n") ? 1 : 0);

                    $entries[] = new FileListEntry(
                        path: $file,
                        status: 'added',
                        oldPath: null,
                        additions: $lineCount,
                        deletions: 0,
                        isBinary: false,
                        isUntracked: true,
                        lastModified: $this->getLastModified($repoPath, $file),
                    );
                }
            }
        }

        return $entries;
    }

    public function getWorkingDirectoryFingerprint(string $repoPath, ?string $globalGitignorePath = null): string
    {
        $excludes = $this->ignoreService->getExcludePathspecs($repoPath);

        $nameStatus = $this->git->run($repoPath, [
            'diff', 'HEAD', '--name-status', '--find-renames',
            '--', '.', ...$excludes,
        ]);

        $lsFilesArgs = ['ls-files', '--others', '--exclude-standard'];
        if ($globalGitignorePath !== null && File::isFile($globalGitignorePath)) {
            $lsFilesArgs[] = '--exclude-from='.$globalGitignorePath;
        }
        $untrackedOutput = $this->git->run($repoPath, $lsFilesArgs);

        $lines = array_filter([
            ...explode("\n", trim($nameStatus)),
            ...array_map(
                fn (string $f) => "?\t".$f,
                array_filter(explode("\n", trim($untrackedOutput))),
            ),
        ]);

        // Filter untracked files through the same excludes as getFileList
        $lines = array_filter($lines, function (string $line) use ($excludes) {
            if (! str_starts_with($line, "?\t")) {
                return true;
            }

            return ! $this->ignoreService->isPathExcluded(substr($line, 2), $excludes);
        });

        sort($lines);

        return hash('xxh128', implode("\n", $lines));
    }

    public function getFileDiff(string $repoPath, string $path, bool $isUntracked = false, ?int $maxBytes = null, int $contextLines = 3, ?DiffTarget $target = null): ?string
    {
        $target ??= DiffTarget::workingDirectory();
        $maxBytes ??= config('rfa.diff_max_bytes', 512_000);

        if ($isUntracked && $target->isWorkingDirectory()) {
            return $this->buildUntrackedDiff($repoPath, $path, $maxBytes);
        }

        $excludes = $this->ignoreService->getExcludePathspecs($repoPath);

        $raw = $this->git->run($repoPath, [
            ...$target->toDiffArgs(),
            '--no-color', '--no-ext-diff', "--unified={$contextLines}", '--text', '--find-renames',
            '--', $path, ...$excludes,
        ]);

        if (strlen($raw) > $maxBytes) {
            return null;
        }

        return $raw;
    }

    private function buildUntrackedDiff(string $repoPath, string $path, int $maxBytes): ?string
    {
        $fullPath = $repoPath.'/'.$path;

        if (! File::isFile($fullPath)) {
            return '';
        }

        if ($this->isBinary($fullPath)) {
            return "diff --git a/{$path} b/{$path}\nnew file mode 100644\nBinary files /dev/null and b/{$path} differ\n";
        }

        $size = File::size($fullPath);
        if ($size > $maxBytes) {
            return null;
        }

        $content = File::get($fullPath);
        if ($content === '') {
            $diff = "diff --git a/{$path} b/{$path}\n";
            $diff .= "new file mode 100644\n";

            return $diff;
        }

        $lines = explode("\n", $content);

        // Strip trailing empty element from files ending with \n
        if (end($lines) === '') {
            array_pop($lines);
        }

        $diff = "diff --git a/{$path} b/{$path}\n";
        $diff .= "new file mode 100644\n";
        $diff .= "--- /dev/null\n";
        $diff .= "+++ b/{$path}\n";
        $diff .= '@@ -0,0 +1,'.count($lines)." @@\n";

        foreach ($lines as $i => $line) {
            $diff .= '+'.$line;
            if ($i < count($lines) - 1) {
                $diff .= "\n";
            }
        }
        $diff .= "\n";

        return $diff;
    }

    private function getLastModified(string $repoPath, string $path): ?string
    {
        $fullPath = $repoPath.'/'.$path;

        if (! File::isFile($fullPath)) {
            return null;
        }

        return Carbon::createFromTimestamp(File::lastModified($fullPath))->diffForHumans(short: true);
    }

    private function isBinary(string $path): bool
    {
        $chunk = substr(File::get($path), 0, 8192);

        return str_contains($chunk, "\0");
    }
}
