<?php

declare(strict_types=1);

namespace App\Actions;

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

        return [
            'dirty' => count($entries) > 0,
            'fileCount' => count($entries),
            'additions' => collect($entries)->sum('additions'),
            'deletions' => collect($entries)->sum('deletions'),
        ];
    }
}
