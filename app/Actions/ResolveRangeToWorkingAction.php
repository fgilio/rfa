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

    /**
     * Resolve a "from-commit through the working tree" target. Typically the
     * caller passes `<oldest-selected>^` so the diff spans every committed
     * change plus uncommitted/untracked edits.
     */
    public function handle(string $repoPath, string $from): DiffTarget
    {
        $effectiveFrom = $from;

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
