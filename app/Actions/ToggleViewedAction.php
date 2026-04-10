<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitDiffService;

final readonly class ToggleViewedAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @param  array<string, string>  $reviewedFiles
     * @param  array<int, array<string, mixed>>  $knownFiles
     * @return array<string, string>|null
     */
    public function handle(array $reviewedFiles, string $filePath, array $knownFiles, string $repoPath = '', ?DiffTarget $target = null): ?array
    {
        $file = collect($knownFiles)->firstWhere('path', $filePath);

        if ($file === null) {
            return null;
        }

        if (array_key_exists($filePath, $reviewedFiles)) {
            unset($reviewedFiles[$filePath]);

            return $reviewedFiles;
        }

        $fingerprint = $repoPath !== ''
            ? $this->gitDiffService->fileDiffFingerprint($repoPath, $filePath, $target)
            : '';

        $reviewedFiles[$filePath] = $fingerprint;

        return $reviewedFiles;
    }
}
