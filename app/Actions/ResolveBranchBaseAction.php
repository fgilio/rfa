<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\BranchBaseResult;
use App\Enums\BranchBaseUnavailableReason;
use App\Exceptions\GitCommandException;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Throwable;

final readonly class ResolveBranchBaseAction
{
    public function __construct(
        private GitProcessService $gitProcessService,
    ) {}

    /**
     * Compute the diff range "since base branch" for the current HEAD.
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

        try {
            $baseSha = $this->resolveBaseRef($repoPath, $base);
        } catch (GitCommandException|ProcessException $exception) {
            return $this->commandUnavailable($base, null, 'resolve_ref', $exception);
        }

        if ($baseSha === null) {
            return BranchBaseResult::missingRef($base);
        }

        try {
            $mergeBase = trim($this->gitProcessService->run($repoPath, ['merge-base', $baseSha, 'HEAD']));
        } catch (GitCommandException|ProcessException $exception) {
            if ($exception instanceof GitCommandException && $exception->exitCode === 1) {
                return BranchBaseResult::unavailable($base, null, BranchBaseUnavailableReason::UnrelatedHistory);
            }

            return $this->commandUnavailable($base, null, 'merge_base', $exception);
        }

        if ($mergeBase === '') {
            return BranchBaseResult::unavailable($base, null, BranchBaseUnavailableReason::UnrelatedHistory);
        }

        try {
            $hashes = $this->commitsBetween($repoPath, $mergeBase, 'HEAD');
        } catch (GitCommandException|ProcessException $exception) {
            return $this->commandUnavailable($base, $mergeBase, 'list_commits', $exception);
        }

        if ($hashes === []) {
            return BranchBaseResult::upToDate($base, $mergeBase);
        }

        return BranchBaseResult::ready($base, $mergeBase, $hashes);
    }

    private function resolveBaseRef(string $repoPath, string $base): ?string
    {
        if (str_starts_with($base, '-')) {
            return null;
        }

        try {
            $resolved = trim($this->gitProcessService->run($repoPath, [
                'rev-parse', '--verify', '--quiet', '--end-of-options', $base.'^{commit}',
            ]));
        } catch (GitCommandException $exception) {
            if ($exception->exitCode === 1) {
                return null;
            }

            throw $exception;
        }

        return $resolved !== '' ? $resolved : null;
    }

    private function commandUnavailable(
        string $baseBranch,
        ?string $baseSha,
        string $stage,
        Throwable $exception,
    ): BranchBaseResult {
        Log::warning('git.branch_base.resolve_failed', [
            'reason' => 'branch_base_resolve_failed',
            'base_branch' => $baseBranch,
            'stage' => $stage,
            'exit_code' => $exception instanceof GitCommandException ? $exception->exitCode : 1,
        ]);

        return BranchBaseResult::unavailable(
            $baseBranch,
            $baseSha,
            BranchBaseUnavailableReason::CommandFailed,
        );
    }

    /**
     * Returns the commit hashes in `from..to`, newest-first, matching the
     * order the picker renders commits. Empty when `to` is at or behind `from`.
     *
     * @return list<string>
     */
    private function commitsBetween(string $repoPath, string $from, string $to): array
    {
        $output = $this->gitProcessService->run($repoPath, [
            'log', '--format=%H', $from.'..'.$to,
        ]);

        return collect(explode("\n", trim($output)))
            ->filter()
            ->values()
            ->all();
    }
}
