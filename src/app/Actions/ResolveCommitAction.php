<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

final readonly class ResolveCommitAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $repoPath, string $ref): ?DiffTarget
    {
        $hash = $this->gitMetadataService->resolveRef($repoPath, $ref);

        if ($hash === null) {
            return null;
        }

        $parents = $this->gitMetadataService->getCommitParents($repoPath, $hash);

        return DiffTarget::commit($hash, $parents[0] ?? null);
    }
}
