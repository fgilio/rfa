<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

final readonly class ResolveRangeAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $repoPath, ?string $from, string $to): DiffTarget
    {
        $effectiveFrom = $from ?? $to.'^';

        // Root commits have no parent, so `<root>^` is not a valid revision.
        // Match the industry convention (GitHub, GitLab, GitLens, ...) and diff
        // against git's empty tree so the root commit renders as pure additions.
        if (str_ends_with($effectiveFrom, '^')
            && $this->gitMetadataService->isRootCommit($repoPath, substr($effectiveFrom, 0, -1))) {
            $effectiveFrom = DiffTarget::EMPTY_TREE_HASH;
        }

        return DiffTarget::fromRefs($effectiveFrom, $to);
    }
}
