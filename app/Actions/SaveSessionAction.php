<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Models\ReviewSession;

final readonly class SaveSessionAction
{
    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<string, string>  $reviewedFiles
     */
    public function handle(string $repoPath, array $comments, array $reviewedFiles, string $globalComment, ?int $projectId = null, string $contextFingerprint = DiffTarget::WORKING_CONTEXT): void
    {
        ReviewSession::updateOrCreate(
            ReviewSession::lookupKey($repoPath, $projectId, $contextFingerprint),
            [
                'repo_path' => $repoPath,
                'reviewed_files' => $reviewedFiles,
                'comments' => $comments,
                'global_comment' => $globalComment,
            ]
        );
    }
}
