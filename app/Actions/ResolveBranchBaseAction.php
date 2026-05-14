<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\BranchBaseResult;
use App\Exceptions\GitCommandException;
use App\Services\GitMetadataService;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\Log;

final readonly class ResolveBranchBaseAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
        private GitProcessService $gitProcessService,
    ) {}

    /**
     * Compute the diff range "since base branch" for the current HEAD.
     *
     * Returns one of five outcomes encoded as a {@see BranchBaseResult}:
     *  - Ready          - base resolved, HEAD is ahead, range hashes returned
     *  - UpToDate       - base resolved but HEAD has no commits ahead of it
     *  - NotConfigured  - no base branch set on the project
     *  - MissingRef     - base branch is configured but the ref isn't local
     *  - OnBaseBranch   - current branch is the configured base
     *
     * `currentBranch` is the working branch (typically the project's branch).
     * Pass `null` (or empty string) for detached HEAD - the action treats it
     * the same as "not on the base branch."
     */
    public function handle(string $repoPath, ?string $baseBranch, ?string $currentBranch): BranchBaseResult
    {
        $base = $baseBranch !== null ? trim($baseBranch) : '';

        if ($base === '') {
            return BranchBaseResult::notConfigured();
        }

        if ($currentBranch !== null && trim($currentBranch) === $base) {
            return BranchBaseResult::onBaseBranch($base);
        }

        $baseSha = $this->gitMetadataService->resolveRef($repoPath, $base);

        if ($baseSha === null) {
            return BranchBaseResult::missingRef($base);
        }

        $mergeBase = $this->gitMetadataService->getMergeBase($repoPath, $base, 'HEAD');

        if ($mergeBase === null) {
            // Unrelated histories - treat like a missing ref so the UI surfaces
            // an actionable state rather than silently doing nothing.
            return BranchBaseResult::missingRef($base);
        }

        $hashes = $this->commitsBetween($repoPath, $mergeBase, 'HEAD');

        if ($hashes === []) {
            return BranchBaseResult::upToDate($base, $mergeBase);
        }

        return BranchBaseResult::ready($base, $mergeBase, $hashes);
    }

    /**
     * Returns the commit hashes in `from..to`, newest-first, matching the
     * order the picker renders commits. Empty when `to` is at or behind `from`.
     *
     * @return list<string>
     */
    private function commitsBetween(string $repoPath, string $from, string $to): array
    {
        try {
            $output = $this->gitProcessService->run($repoPath, [
                'log', '--format=%H', $from.'..'.$to,
            ]);
        } catch (GitCommandException $e) {
            Log::warning('git.commit_range.list_failed', [
                'reason' => 'commit_range_list_failed',
                'repo' => $repoPath,
                'from' => $from,
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return collect(explode("\n", trim($output)))
            ->filter()
            ->values()
            ->all();
    }
}
