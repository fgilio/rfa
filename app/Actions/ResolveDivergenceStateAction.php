<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CurrentHeadResult;
use App\DTOs\DivergenceDecision;
use Closure;

/**
 * Decide how the review page should react to the repository's current HEAD,
 * given the branch under review and which divergences the user already
 * dismissed.
 *
 * The comment lookups arrive as closures so their DB queries run only on the
 * branch that needs them. The common aligned path (HEAD on the reviewed
 * branch) makes none, which matters because this runs on every poll tick and
 * every comment write.
 */
final readonly class ResolveDivergenceStateAction
{
    /**
     * @param  Closure(): bool  $hasPersistedComments
     * @param  Closure(): int  $persistedCommentCount
     * @param  bool  $isInitialResolve  True on the first resolve after mount (a
     *                                  fresh open, e.g. the `rfa` CLI deep-link).
     *                                  There is no in-progress review to protect
     *                                  yet, so a stored target that no longer
     *                                  exists auto-follows HEAD's checked-out
     *                                  branch instead of surfacing the blocking
     *                                  missing-target banner. Poll ticks pass
     *                                  false so a branch vanishing mid-review
     *                                  still surfaces the banner.
     */
    public function handle(
        CurrentHeadResult $head,
        string $projectBranch,
        ?string $dismissedAtHead,
        ?string $dismissedAtBranch,
        Closure $hasPersistedComments,
        Closure $persistedCommentCount,
        bool $isInitialResolve = false,
    ): DivergenceDecision {
        // Sentinel: GetCurrentHeadAction returns sha='' when git fails transiently
        // (e.g. mid-rebase). Leave state untouched and retry next tick.
        if ($head->sha === '') {
            return DivergenceDecision::noop();
        }

        if (! $head->detached && $head->branch === $projectBranch) {
            return DivergenceDecision::aligned();
        }

        $target = $projectBranch;

        if ($head->detached) {
            if ($dismissedAtHead === $head->sha) {
                return DivergenceDecision::aligned();
            }

            return DivergenceDecision::detached($target, $head->sha);
        }

        // `targetExists` is tri-state: true exists, false is a confirmed
        // absence, null means the existence probe couldn't complete (transient
        // git failure). Treat anything that isn't a confirmed presence as a
        // missing target for banner purposes, but only *persist* a retarget when
        // the absence is confirmed (see below).
        if ($target !== '' && $head->targetExists !== true) {
            if ($dismissedAtBranch === $head->branch) {
                return DivergenceDecision::aligned();
            }

            // Fresh open with the target confirmed gone: it's stale state from a
            // prior session and can't be reviewed anyway, so land the user on the
            // branch they have checked out rather than nagging. An unconfirmed
            // (null) probe stays on the recoverable banner — silently overwriting
            // the saved target on a transient failure could strand the user off a
            // branch that still exists.
            if ($isInitialResolve && $head->targetExists === false) {
                return DivergenceDecision::autoFollow((string) $head->branch);
            }

            return DivergenceDecision::missingTarget($target, (string) $head->branch, $head->sha);
        }

        if (! $hasPersistedComments()) {
            return DivergenceDecision::autoFollow((string) $head->branch);
        }

        // Suppress by branch identity, not sha: once the user opts to keep reviewing
        // their target, committing on the diverged branch shouldn't re-nag every commit.
        if ($dismissedAtBranch === $head->branch) {
            return DivergenceDecision::aligned();
        }

        return DivergenceDecision::diverged($target, (string) $head->branch, $head->sha, $persistedCommentCount());
    }
}
