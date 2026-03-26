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
     * @param  array<string, string>  $viewedFiles
     * @param  array<int, array<string, mixed>>  $knownFiles
     * @return array<string, string>|null
     */
    public function handle(array $viewedFiles, string $filePath, array $knownFiles, string $repoPath = '', ?DiffTarget $target = null): ?array
    {
        $file = collect($knownFiles)->firstWhere('path', $filePath);

        if ($file === null) {
            return null;
        }

        if (array_key_exists($filePath, $viewedFiles)) {
            unset($viewedFiles[$filePath]);

            return $viewedFiles;
        }

        $fingerprint = $repoPath !== ''
            ? $this->gitDiffService->fileDiffFingerprint($repoPath, $filePath, $target)
            : '';

        $viewedFiles[$filePath] = $fingerprint;

        return $viewedFiles;
    }
}
