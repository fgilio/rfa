<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Enums\DiffSide;
use App\Models\ReviewedFile;
use App\Services\GitFileContentService;

final readonly class ToggleReviewedAction
{
    public function __construct(
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * @param  array<string, string>  $reviewedFiles  Current view state: {path => content_hash}.
     * @param  array<int, array{id?: string, path: string, isUntracked?: bool, isExternal?: bool, externalAbsolutePath?: ?string}>  $knownFiles  Files in the current diff.
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

        $contentHash = ($file['isExternal'] ?? false) && ! empty($file['externalAbsolutePath'])
            ? ($this->gitFileContentService->hashForSource($repoPath, FileSourceSpec::absolute((string) $file['externalAbsolutePath'])) ?? '')
            : ($repoPath !== '' ? ($this->resolveContentHash($repoPath, $target, $filePath) ?? '') : '');

        if (array_key_exists($filePath, $reviewedFiles)) {
            ReviewedFile::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->where('file_path', $filePath)
                ->delete();
            unset($reviewedFiles[$filePath]);

            return $reviewedFiles;
        }

        ReviewedFile::updateOrCreate(
            [
                'project_id' => $projectId,
                'repo_path' => $repoPath,
                'file_path' => $filePath,
            ],
            ['content_hash' => $contentHash],
        );

        $reviewedFiles[$filePath] = $contentHash;

        return $reviewedFiles;
    }

    /**
     * Pin the reviewed anchor to a stable hash: right side first, falling back
     * to the left side for files that only exist before the diff (deletions /
     * left-only moves). Returns null when the file is absent on both sides.
     */
    private function resolveContentHash(string $repoPath, ?DiffTarget $target, string $filePath): ?string
    {
        $rightSource = $this->sideSource($target, DiffSide::Right, $filePath);
        $rightHash = $this->gitFileContentService->hashForSource($repoPath, $rightSource);
        if ($rightHash !== null) {
            return $rightHash;
        }

        $leftSource = $this->sideSource($target, DiffSide::Left, $filePath);

        // The two sides collapse to the same blob when comparing the working
        // tree against itself, so there is nothing new to read on the left.
        return $leftSource->ref === $rightSource->ref
            ? null
            : $this->gitFileContentService->hashForSource($repoPath, $leftSource);
    }

    /**
     * Resolve one side's source, treating a null target (raw working-tree
     * review without a diff) as the working copy on both sides.
     */
    private function sideSource(?DiffTarget $target, DiffSide $side, string $filePath): FileSourceSpec
    {
        return $target === null
            ? FileSourceSpec::working($filePath)
            : FileSourceSpec::forSide($target, $side, $filePath);
    }
}
