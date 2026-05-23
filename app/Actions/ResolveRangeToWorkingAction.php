<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

final readonly class ResolveRangeToWorkingAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $repoPath, string $from): DiffTarget
    {
        $effectiveFrom = $this->gitMetadataService->resolveRefExpression($repoPath, $from);

        // Root commits have no parent, so `<root>^` is not a valid revision.
        // Match ResolveRangeAction and diff against git's empty tree so the
        // earliest included commit renders as pure additions.
        if (str_ends_with($effectiveFrom, '^')
            && $this->gitMetadataService->isRootCommit($repoPath, substr($effectiveFrom, 0, -1))) {
            $effectiveFrom = DiffTarget::EMPTY_TREE_HASH;
        }

        return DiffTarget::rangeToWorking($effectiveFrom);
    }
}
