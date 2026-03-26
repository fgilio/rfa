<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitDiffService;

final readonly class CheckForChangesAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    public function handle(string $repoPath, ?string $globalGitignorePath = null): string
    {
        return $this->gitDiffService->getWorkingDirectoryFingerprint($repoPath, $globalGitignorePath);
    }
}
