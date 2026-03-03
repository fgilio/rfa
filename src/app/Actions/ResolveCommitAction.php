<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitDiffService;

final readonly class ResolveCommitAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    public function handle(string $repoPath, string $ref): ?DiffTarget
    {
        $hash = $this->gitDiffService->resolveRef($repoPath, $ref);

        if ($hash === null) {
            return null;
        }

        $parents = $this->gitDiffService->getCommitParents($repoPath, $hash);

        return DiffTarget::commit($hash, $parents[0] ?? null);
    }
}
