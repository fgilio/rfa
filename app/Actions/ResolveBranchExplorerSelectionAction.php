<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\BranchExplorerSelectionResult;
use App\Enums\BranchBaseState;

final readonly class ResolveBranchExplorerSelectionAction
{
    public function __construct(
        private LoadBranchExplorerSnapshotAction $loadSnapshot,
    ) {}

    /**
     * @param  list<string>  $selectedHashes
     */
    public function handle(
        string $repoPath,
        string $projectSlug,
        string $selectedBranch,
        string $currentBranch,
        ?string $baseBranch,
        array $selectedHashes,
        bool $workingTreeSelected,
        string $snapshotKey,
        int $pageSize,
        int $minimumCommitCount,
    ): BranchExplorerSelectionResult {
        $selectedHashes = $this->normalizeSelectedHashes($selectedHashes);

        if (! $workingTreeSelected && $selectedHashes === []) {
            return BranchExplorerSelectionResult::noop();
        }

        // Apply deliberately re-reads git state so a stale drawer cannot navigate to the wrong diff.
        $snapshot = $this->loadSnapshot->handle(
            repoPath: $repoPath,
            selectedBranch: $selectedBranch,
            currentBranch: $currentBranch,
            baseBranch: $baseBranch,
            pageSize: $pageSize,
            minimumCommitCount: max($minimumCommitCount, count($selectedHashes)),
            includeBranches: false,
        );

        if ($snapshot->snapshotKey !== $snapshotKey) {
            return BranchExplorerSelectionResult::stale('Commit list changed. Refreshed the picker before applying.');
        }

        if ($workingTreeSelected && $selectedBranch !== $currentBranch) {
            return BranchExplorerSelectionResult::error('Working tree can only be paired with commits from the current branch.');
        }

        if ($this->isExactSinceBaseSelection($snapshot->branchBase, $selectedBranch, $currentBranch, $selectedHashes, $workingTreeSelected)) {
            return BranchExplorerSelectionResult::navigate($this->rangeToWorkingUrl($projectSlug, (string) $snapshot->branchBase['baseSha']));
        }

        $indices = $this->selectedCommitIndices($snapshot->commits, $selectedHashes);

        if (count($indices) !== count($selectedHashes)) {
            return BranchExplorerSelectionResult::stale('Selected commits are no longer in the loaded list. Refreshed the picker.');
        }

        if ($workingTreeSelected && $indices === []) {
            return BranchExplorerSelectionResult::navigate($this->projectUrl($projectSlug));
        }

        if ($indices === []) {
            return BranchExplorerSelectionResult::noop();
        }

        if (! $this->isContiguous($indices)) {
            return BranchExplorerSelectionResult::error(
                'Selection is not contiguous - pick every commit between the oldest and newest you want to review.',
            );
        }

        if ($workingTreeSelected && ! in_array(0, $indices, true)) {
            return BranchExplorerSelectionResult::error(
                'Selection is not contiguous - working tree must be paired with the newest commits.',
            );
        }

        if ($workingTreeSelected) {
            $oldest = $snapshot->commits[$indices[count($indices) - 1]];

            return BranchExplorerSelectionResult::navigate(
                $this->rangeToWorkingUrl($projectSlug, (string) $oldest['hash'].'^'),
            );
        }

        if (count($indices) === 1) {
            return BranchExplorerSelectionResult::navigate(
                $this->commitUrl($projectSlug, (string) $snapshot->commits[$indices[0]]['hash']),
            );
        }

        $newest = $snapshot->commits[$indices[0]];
        $oldest = $snapshot->commits[$indices[count($indices) - 1]];

        return BranchExplorerSelectionResult::navigate(
            $this->rangeUrl($projectSlug, (string) $newest['hash'], (string) $oldest['hash'].'^'),
        );
    }

    /**
     * @param  array<mixed>  $selectedHashes
     * @return list<string>
     */
    private function normalizeSelectedHashes(array $selectedHashes): array
    {
        return collect($selectedHashes)
            ->filter(fn (mixed $hash): bool => is_string($hash) && $hash !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int}  $branchBase
     * @param  list<string>  $selectedHashes
     */
    private function isExactSinceBaseSelection(
        array $branchBase,
        string $selectedBranch,
        string $currentBranch,
        array $selectedHashes,
        bool $workingTreeSelected,
    ): bool {
        if ($selectedBranch !== $currentBranch || ! $workingTreeSelected) {
            return false;
        }

        if ($branchBase['state'] !== BranchBaseState::Ready->value || $branchBase['baseSha'] === null) {
            return false;
        }

        if (count($selectedHashes) !== count($branchBase['hashesInRange'])) {
            return false;
        }

        $range = array_flip($branchBase['hashesInRange']);

        return collect($selectedHashes)->every(
            fn (string $hash): bool => array_key_exists($hash, $range),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $commits
     * @param  list<string>  $selectedHashes
     * @return list<int>
     */
    private function selectedCommitIndices(array $commits, array $selectedHashes): array
    {
        $indexByHash = collect($commits)
            ->mapWithKeys(fn (array $commit, int $index): array => [(string) $commit['hash'] => $index])
            ->all();

        return collect($selectedHashes)
            ->filter(fn (string $hash): bool => array_key_exists($hash, $indexByHash))
            ->map(fn (string $hash): int => $indexByHash[$hash])
            ->sort()
            ->values()
            ->all();
    }

    /** @param  list<int>  $indices */
    private function isContiguous(array $indices): bool
    {
        if ($indices === []) {
            return false;
        }

        return count($indices) === $indices[count($indices) - 1] - $indices[0] + 1;
    }

    private function projectUrl(string $slug): string
    {
        return route('review-page', ['slug' => $slug], false);
    }

    private function commitUrl(string $slug, string $hash): string
    {
        return route('review-page.commit', ['slug' => $slug, 'hash' => $hash], false);
    }

    private function rangeUrl(string $slug, string $newest, string $baseRef): string
    {
        return route('review-page', ['slug' => $slug, 'ref' => $newest, 'baseRef' => $baseRef], false);
    }

    private function rangeToWorkingUrl(string $slug, string $from): string
    {
        return route('review-page.range-to-working', ['slug' => $slug, 'rangeFromWorking' => $from], false);
    }
}
