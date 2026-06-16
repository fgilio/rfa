<?php

declare(strict_types=1);

namespace App\Concerns\ReviewPage;

use App\Actions\CountPersistedCommentsAction;
use App\Actions\GetCurrentHeadAction;
use App\Actions\ResolveDivergenceStateAction;
use App\Actions\UpdateProjectSettingAction;
use App\DTOs\CurrentHeadResult;
use App\DTOs\DivergenceDecision;
use App\Enums\DivergenceDecisionKind;
use App\Enums\DivergenceState;
use Livewire\Attributes\On;

/**
 * Branch-divergence detection and resolution for the review page.
 *
 * Owns the divergence state machine: polls HEAD, decides whether the review
 * has drifted from its target branch, and applies the chosen transition
 * (align, auto-follow, or surface a banner). The decision tree lives in
 * ResolveDivergenceStateAction; this trait sequences it against component
 * state and the render pipeline.
 *
 * Component state read/written: $divergenceState, $divergenceContext,
 * $dismissedAtHead, $dismissedAtBranch, $divergenceChecked, $projectBranch,
 * $projectId, $repoPath. Calls into the coordinator spine (isCommitMode,
 * softRefresh, rehydrateForTarget) and the render pipeline (skipRender,
 * dispatch).
 */
trait ReviewsBranchDivergence
{
    /** Undo-toast `type` for switching the review to a new target branch. */
    private const UNDO_TYPE_SWITCH_BRANCH = 'switch-branch';

    #[On('head-divergence-transitioned')]
    public function checkHeadDivergence(): void
    {
        if (! $this->refreshDivergenceState()) {
            $this->skipRender();
        }
    }

    /**
     * HEAD advanced on the same branch the user is reviewing, typically
     * because they just committed. Reload the file list so the diff reflects
     * the new commit. Commit/range modes pin both endpoints, so a HEAD move
     * cannot affect what's shown; skip render in that case.
     */
    #[On('head-advanced-on-branch')]
    public function refreshAfterHeadAdvance(): void
    {
        if ($this->isCommitMode()) {
            $this->skipRender();

            return;
        }

        $this->softRefresh();
    }

    /**
     * Recompute divergence state. Returns true if state changed since the last
     * check (i.e. the caller should render), false when the caller can skip.
     * Kept separate from `checkHeadDivergence()` so callers like `softRefresh`
     * can update divergence without latching `skipRender()` onto a response
     * that still needs to morph because files did change.
     */
    private function refreshDivergenceState(): bool
    {
        if ($this->isCommitMode()) {
            $changed = ! $this->divergenceChecked;
            $this->divergenceChecked = true;

            return $changed;
        }

        $before = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->dismissedAtBranch, $this->projectBranch];

        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);
        $this->resolveDivergenceState($head);

        $after = [$this->divergenceState, $this->divergenceContext, $this->dismissedAtHead, $this->dismissedAtBranch, $this->projectBranch];

        $changed = ! $this->divergenceChecked || $before !== $after;
        $this->divergenceChecked = true;

        return $changed;
    }

    private function resolveDivergenceState(CurrentHeadResult $head): void
    {
        $decision = app(ResolveDivergenceStateAction::class)->handle(
            $head,
            $this->projectBranch,
            $this->dismissedAtHead,
            $this->dismissedAtBranch,
            fn (): bool => $this->hasPersistedComments(),
            fn (): int => $this->persistedCommentCount(),
        );

        match ($decision->kind) {
            DivergenceDecisionKind::Noop => null,
            DivergenceDecisionKind::Aligned => $this->markAligned(),
            DivergenceDecisionKind::AutoFollow => $this->autoFollowToHead((string) $decision->autoFollowBranch),
            DivergenceDecisionKind::Show => $this->showDivergence($decision),
        };
    }

    private function showDivergence(DivergenceDecision $decision): void
    {
        $this->divergenceState = $decision->state ?? DivergenceState::Aligned;
        $this->divergenceContext = $decision->context;
    }

    public function switchReviewToHead(): void
    {
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->projectBranch ?: null);

        if ($head->detached || $head->branch === null || $head->branch === '') {
            return;
        }

        // Capture before autoFollow clears state, so the switch stays undoable.
        $wasDiverged = $this->divergenceState === DivergenceState::Diverged;
        $fromBranch = $this->projectBranch;

        $this->autoFollowToHead($head->branch);

        // Only offer undo when leaving a real, still-existing target (Diverged).
        // MissingTarget's old branch is gone, so undoing would re-point at nothing.
        if ($wasDiverged && $fromBranch !== '' && $fromBranch !== $head->branch) {
            $this->dispatch(
                'undo-available',
                type: self::UNDO_TYPE_SWITCH_BRANCH,
                payload: ['fromBranch' => $fromBranch],
                message: 'Switched review to '.$head->branch,
            );
        }
    }

    public function keepReviewing(): void
    {
        $branch = $this->divergenceContext['currentBranch'] ?? null;

        if (is_string($branch) && $branch !== '') {
            // Diverged / missing-target: suppress by branch identity.
            $this->dismissedAtBranch = $branch;
        } else {
            // Detached: no branch to key on, so fall back to the sha.
            $sha = $this->divergenceContext['currentSha'] ?? null;

            if (is_string($sha) && $sha !== '') {
                $this->dismissedAtHead = $sha;
            }
        }

        $this->markAligned();
    }

    public function dismissDetachedBanner(): void
    {
        $this->keepReviewing();
    }

    public function dismissMissingTarget(): void
    {
        $this->keepReviewing();
    }

    /** @param array{fromBranch?: string} $payload */
    public function restoreReviewBranch(array $payload): void
    {
        $branch = $payload['fromBranch'] ?? null;

        if (is_string($branch) && $branch !== '') {
            $this->autoFollowToHead($branch);

            // autoFollowToHead() aligns to the restored target, which is right for
            // its "follow HEAD" callers. Here HEAD is still on the branch we
            // switched away from, so the review is diverged again. Recompute now:
            // the head poller won't re-fire (HEAD's identity hasn't changed) and
            // would otherwise leave the divergence marker hidden indefinitely.
            $this->refreshDivergenceState();
        }
    }

    private function autoFollowToHead(string $newBranch): void
    {
        // Race guard: overlapping polls during a slow rehydrate can re-enter here.
        if ($this->projectBranch === $newBranch) {
            $this->markAligned();

            return;
        }

        app(UpdateProjectSettingAction::class)->handle($this->projectId, ['branch' => $newBranch]);

        $this->projectBranch = $newBranch;
        $this->dismissedAtHead = null;
        $this->dismissedAtBranch = null;
        $this->markAligned();

        $this->rehydrateForTarget();
    }

    private function markAligned(): void
    {
        $this->divergenceState = DivergenceState::Aligned;
        $this->divergenceContext = [];
    }

    private function hasPersistedComments(): bool
    {
        return app(CountPersistedCommentsAction::class)->exists(
            $this->repoPath,
            $this->projectId === 0 ? null : $this->projectId,
        );
    }

    private function persistedCommentCount(): int
    {
        return app(CountPersistedCommentsAction::class)->handle(
            $this->repoPath,
            $this->projectId === 0 ? null : $this->projectId,
        );
    }
}
