<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\BranchExplorerSnapshot;
use App\Services\GitMetadataService;

final readonly class LoadBranchExplorerSnapshotAction
{
    public function __construct(
        private GetBranchListAction $getBranchList,
        private GetCommitHistoryAction $getCommitHistory,
        private ResolveBranchBaseAction $resolveBranchBase,
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(
        string $repoPath,
        string $selectedBranch,
        string $currentBranch,
        ?string $baseBranch,
        int $pageSize = 50,
        int $minimumCommitCount = 0,
        bool $includeBranches = true,
    ): BranchExplorerSnapshot {
        $pageSize = max(1, $pageSize);
        $branch = $this->normalizeBranch($selectedBranch, $currentBranch);

        $branches = $includeBranches
            ? $this->getBranchList->handle($repoPath)
            : ['local' => [], 'remote' => [], 'current' => ''];

        $branchBase = $this->resolveBranchBase
            ->handle($repoPath, $baseBranch, $currentBranch !== '' ? $currentBranch : null)
            ->toArray();

        $loadedLimit = $this->loadedLimit($branch, $currentBranch, $branchBase, $pageSize, $minimumCommitCount);
        $commits = $this->getCommitHistory->handle($repoPath, $loadedLimit + 1, 0, $branch);
        $hasMore = count($commits) > $loadedLimit;
        $visibleCommits = array_slice($commits, 0, $loadedLimit);
        $tipSha = $this->selectedBranchTipSha($repoPath, $branch);

        return new BranchExplorerSnapshot(
            branches: $branches,
            selectedBranch: $branch,
            selectedBranchTipSha: $tipSha,
            branchBase: $branchBase,
            commits: $visibleCommits,
            hasMore: $hasMore,
            pageSize: $pageSize,
            loadedLimit: $loadedLimit,
            snapshotKey: BranchExplorerSnapshot::keyFor($branch, $tipSha, $branchBase),
        );
    }

    private function normalizeBranch(string $selectedBranch, string $currentBranch): string
    {
        $branch = trim($selectedBranch);

        return $branch !== '' ? $branch : trim($currentBranch);
    }

    /**
     * @param  array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int, unavailableReason: ?string}  $branchBase
     */
    private function loadedLimit(string $branch, string $currentBranch, array $branchBase, int $pageSize, int $minimumCommitCount): int
    {
        $minimum = max($pageSize, $minimumCommitCount);

        if ($branch !== $currentBranch) {
            return $minimum;
        }

        return max($minimum, $branchBase['commitCount']);
    }

    private function selectedBranchTipSha(string $repoPath, string $branch): ?string
    {
        if ($branch === '') {
            return null;
        }

        return $this->gitMetadataService->resolveRef($repoPath, $branch);
    }
}
