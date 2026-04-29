<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitMetadataService;

final readonly class IsSinceBaseViewAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    /**
     * True when `$diffFrom` resolves to the same SHA as the merge-base of
     * `$baseBranch` and HEAD - i.e., the current rangeToWorking view is
     * exactly "since {base}". Used by the page to render the header label.
     */
    public function handle(string $repoPath, ?string $baseBranch, string $diffFrom): bool
    {
        $base = $baseBranch !== null ? trim($baseBranch) : '';

        if ($base === '' || $diffFrom === 'HEAD') {
            return false;
        }

        $mergeBase = $this->gitMetadataService->getMergeBase($repoPath, $base, 'HEAD');

        if ($mergeBase === null) {
            return false;
        }

        $diffFromSha = $this->gitMetadataService->resolveRef($repoPath, $diffFrom);

        return $diffFromSha !== null && $diffFromSha === $mergeBase;
    }
}
