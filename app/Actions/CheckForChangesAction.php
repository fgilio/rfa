<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitDiffService;

final readonly class CheckForChangesAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @return array{fingerprint: string, count: int}
     */
    public function handle(string $repoPath, ?string $globalGitignorePath = null): array
    {
        return $this->gitDiffService->getWorkingDirectoryStatus($repoPath, $globalGitignorePath);
    }
}
