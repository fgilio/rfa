<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\FileListEntry;
use App\DTOs\ReviewFilePair;
use App\Exceptions\GitCommandException;
use App\Services\GitDiffService;
use App\Support\LogSanitizer;
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
            Log::warning('project.status.failed', [
                'reason' => 'project_status_failed',
                'exit_code' => $e->exitCode,
                'stderr_summary' => LogSanitizer::summary($e->stderr),
            ]);

            return ['dirty' => false, 'fileCount' => 0, 'additions' => 0, 'deletions' => 0];
        }

        // Exclude RFA's own review files (comment exports + snapshots) from metrics
        $sourceEntries = array_filter($entries, fn (FileListEntry $e) => ! ReviewFilePair::isArtifactPath($e->path));

        return [
            'dirty' => count($sourceEntries) > 0,
            'fileCount' => count($sourceEntries),
            'additions' => collect($sourceEntries)->sum('additions'),
            'deletions' => collect($sourceEntries)->sum('deletions'),
        ];
    }
}
