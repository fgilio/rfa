<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class GitDiffService
{
    public function __construct(
        private readonly IgnoreService $ignoreService,
    ) {}

    public function getDiff(string $repoPath): string
    {
        $excludes = $this->ignoreService->getExcludePathspecs($repoPath);

        // Get diff of tracked files (staged + unstaged vs HEAD)
        $trackedDiff = $this->runGit($repoPath, [
            'diff', 'HEAD',
            '--no-color', '--no-ext-diff', '--unified=3', '--text', '--find-renames',
            '--', '.', ...$excludes,
        ]);

        // Get untracked files
        $untrackedOutput = $this->runGit($repoPath, [
            'ls-files', '--others', '--exclude-standard',
        ]);

        $untrackedDiff = '';
        if (trim($untrackedOutput) !== '') {
            $untrackedFiles = array_filter(explode("\n", trim($untrackedOutput)));
            $excludePatterns = $excludes;

            foreach ($untrackedFiles as $file) {
                if ($this->isExcluded($file, $excludePatterns)) {
                    continue;
                }

                $fullPath = $repoPath.'/'.$file;
                if (! is_file($fullPath) || ! is_readable($fullPath)) {
                    continue;
                }

                // Check if binary
                if ($this->isBinary($fullPath)) {
                    $untrackedDiff .= "diff --git a/{$file} b/{$file}\n";
                    $untrackedDiff .= "new file mode 100644\n";
                    $untrackedDiff .= "Binary files /dev/null and b/{$file} differ\n";

                    continue;
                }

                $content = file_get_contents($fullPath);
                $lines = explode("\n", $content);

                $untrackedDiff .= "diff --git a/{$file} b/{$file}\n";
                $untrackedDiff .= "new file mode 100644\n";
                $untrackedDiff .= "--- /dev/null\n";
                $untrackedDiff .= "+++ b/{$file}\n";
                $untrackedDiff .= '@@ -0,0 +1,'.count($lines)." @@\n";

                foreach ($lines as $i => $line) {
                    $untrackedDiff .= '+'.$line;
                    if ($i < count($lines) - 1) {
                        $untrackedDiff .= "\n";
                    }
                }
                $untrackedDiff .= "\n";
            }
        }

        return trim($trackedDiff."\n".$untrackedDiff);
    }

    /** @param array<int, string> $args */
    private function runGit(string $repoPath, array $args): string
    {
        $process = new Process(['git', '-C', $repoPath, ...$args]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return '';
        }

        return $process->getOutput();
    }

    /** @param array<int, string> $excludePatterns */
    private function isExcluded(string $file, array $excludePatterns): bool
    {
        foreach ($excludePatterns as $pattern) {
            // Strip :(exclude) prefix
            $glob = str_replace(':(exclude)', '', $pattern);
            if (fnmatch($glob, $file) || fnmatch($glob, basename($file))) {
                return true;
            }
        }

        return false;
    }

    private function isBinary(string $path): bool
    {
        $chunk = file_get_contents($path, false, null, 0, 8192);

        return $chunk !== false && str_contains($chunk, "\0");
    }
}
