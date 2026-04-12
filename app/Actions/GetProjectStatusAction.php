<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\FileListEntry;
use App\DTOs\ReviewFilePair;
use App\Exceptions\GitCommandException;
use App\Services\GitDiffService;
use Illuminate\Support\Facades\Log;

final readonly class GetProjectStatusAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @return array{dirty: bool, fileCount: int, additions: int, deletions: int}
     */
    public function handle(string $repoPath, ?string $globalGitignorePath = null): array
    {
        try {
            $entries = $this->gitDiffService->getFileList($repoPath, $globalGitignorePath);
        } catch (GitCommandException $e) {
            Log::warning('Failed to get project status', ['repoPath' => $repoPath, 'stderr' => $e->stderr]);

            return ['dirty' => false, 'fileCount' => 0, 'additions' => 0, 'deletions' => 0];
        }

        // Exclude RFA's own review files from metrics
        $sourceEntries = array_filter($entries, fn (FileListEntry $e) => ReviewFilePair::extractBasename($e->path) === null);

        return [
            'dirty' => count($sourceEntries) > 0,
            'fileCount' => count($sourceEntries),
            'additions' => collect($sourceEntries)->sum('additions'),
            'deletions' => collect($sourceEntries)->sum('deletions'),
        ];
    }
}
