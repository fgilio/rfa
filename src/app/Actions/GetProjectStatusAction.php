<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GitCommandException;
use App\Services\GitProcessService;
use App\Services\IgnoreService;
use Illuminate\Support\Facades\File;

final readonly class GetProjectStatusAction
{
    public function __construct(
        private GitProcessService $git,
        private IgnoreService $ignoreService,
    ) {}

    /**
     * @return array{dirty: bool, fileCount: int, additions: int, deletions: int}
     */
    public function handle(string $repoPath, ?string $globalGitignorePath = null): array
    {
        $excludes = $this->ignoreService->getExcludePathspecs($repoPath);

        try {
            $numstat = $this->git->run($repoPath, [
                'diff', 'HEAD', '--numstat', '--find-renames',
                '--', '.', ...$excludes,
            ]);
        } catch (GitCommandException) {
            return ['dirty' => false, 'fileCount' => 0, 'additions' => 0, 'deletions' => 0];
        }

        $additions = 0;
        $deletions = 0;
        $fileCount = 0;

        foreach (array_filter(explode("\n", trim($numstat))) as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 3) {
                continue;
            }

            $fileCount++;
            if ($parts[0] !== '-') {
                $additions += (int) $parts[0];
                $deletions += (int) $parts[1];
            }
        }

        // Count untracked files
        $lsFilesArgs = ['ls-files', '--others', '--exclude-standard'];
        if ($globalGitignorePath !== null && File::isFile($globalGitignorePath)) {
            $lsFilesArgs[] = '--exclude-from='.$globalGitignorePath;
        }

        try {
            $untrackedOutput = $this->git->run($repoPath, $lsFilesArgs);
        } catch (GitCommandException) {
            $untrackedOutput = '';
        }

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

                $fileCount++;
                $content = File::get($fullPath);
                $additions += substr_count($content, "\n") + ($content !== '' && ! str_ends_with($content, "\n") ? 1 : 0);
            }
        }

        return [
            'dirty' => $fileCount > 0,
            'fileCount' => $fileCount,
            'additions' => $additions,
            'deletions' => $deletions,
        ];
    }
}
