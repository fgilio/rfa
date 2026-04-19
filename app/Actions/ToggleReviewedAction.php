<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Models\ReviewedFile;
use App\Services\GitFileContentService;

final readonly class ToggleReviewedAction
{
    public function __construct(
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * @param  array<string, string>  $reviewedFiles  Current view state: {path => content_hash}.
     * @param  array<int, array<string, mixed>>  $knownFiles  Files in the current diff.
     * @return array<string, string>|null Updated view state, or null when the file is unknown.
     */
    public function handle(
        array $reviewedFiles,
        string $filePath,
        array $knownFiles,
        string $repoPath,
        ?DiffTarget $target = null,
        ?int $projectId = null,
    ): ?array {
        $file = collect($knownFiles)->firstWhere('path', $filePath);

        if ($file === null) {
            return null;
        }

        $ref = ($target?->to()) ?? GitFileContentService::WORKING_REF;
        $contentHash = $repoPath !== ''
            ? ($this->gitFileContentService->hashAt($repoPath, $ref, $filePath) ?? '')
            : '';

        $query = ReviewedFile::query()->where('file_path', $filePath);
        $query = $projectId
            ? $query->where('project_id', $projectId)
            : $query->whereNull('project_id')->where('repo_path', $repoPath);

        if (array_key_exists($filePath, $reviewedFiles)) {
            (clone $query)->delete();
            unset($reviewedFiles[$filePath]);

            return $reviewedFiles;
        }

        ReviewedFile::updateOrCreate(
            [
                'project_id' => $projectId,
                'repo_path' => $repoPath,
                'file_path' => $filePath,
                'content_hash' => $contentHash,
            ],
            [],
        );

        $reviewedFiles[$filePath] = $contentHash;

        return $reviewedFiles;
    }
}
