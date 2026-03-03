<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Models\ReviewSession;

final readonly class SaveSessionAction
{
    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, string>  $viewedFiles
     */
    public function handle(string $repoPath, array $comments, array $viewedFiles, string $globalComment, ?int $projectId = null, string $contextFingerprint = DiffTarget::WORKING_CONTEXT): void
    {
        ReviewSession::updateOrCreate(
            ReviewSession::scopeKey($repoPath, $projectId, $contextFingerprint),
            [
                'repo_path' => $repoPath,
                'viewed_files' => $viewedFiles,
                'comments' => $comments,
                'global_comment' => $globalComment,
            ]
        );
    }
}
