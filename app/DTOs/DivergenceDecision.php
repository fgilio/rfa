<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DivergenceDecisionKind;
use App\Enums\DivergenceState;

/**
 * Outcome of evaluating the repository HEAD against the branch under review.
 *
 * Pure value object: ResolveDivergenceStateAction produces it and the review
 * page applies the matching side effect. The named constructors own the
 * banner-context shape (including the short sha) so every Show variant agrees
 * on the keys the marker and bar read.
 */
final readonly class DivergenceDecision
{
    /** @param array<string, mixed> $context */
    private function __construct(
        public DivergenceDecisionKind $kind,
        public ?DivergenceState $state = null,
        public ?string $autoFollowBranch = null,
        public array $context = [],
    ) {}

    /** Transient git failure (empty sha): keep the current state, retry next tick. */
    public static function noop(): self
    {
        return new self(DivergenceDecisionKind::Noop);
    }

    /** HEAD matches the target, or the user dismissed this divergence. */
    public static function aligned(): self
    {
        return new self(DivergenceDecisionKind::Aligned);
    }

    /** No comments are at risk, so silently retarget the review to HEAD's branch. */
    public static function autoFollow(string $branch): self
    {
        return new self(DivergenceDecisionKind::AutoFollow, autoFollowBranch: $branch);
    }

    /** HEAD detached from the target while it is still being reviewed. */
    public static function detached(string $target, string $currentSha): self
    {
        return new self(
            DivergenceDecisionKind::Show,
            state: DivergenceState::Detached,
            context: [
                'target' => $target,
                'currentBranch' => null,
                'currentSha' => $currentSha,
                'shortSha' => substr($currentSha, 0, 7),
            ],
        );
    }

    /** The reviewed branch no longer exists in the repository. */
    public static function missingTarget(string $target, string $currentBranch, string $currentSha): self
    {
        return new self(
            DivergenceDecisionKind::Show,
            state: DivergenceState::MissingTarget,
            context: [
                'target' => $target,
                'currentBranch' => $currentBranch,
                'currentSha' => $currentSha,
                'shortSha' => substr($currentSha, 0, 7),
            ],
        );
    }

    /** HEAD moved to a different branch while comments exist on the target. */
    public static function diverged(string $target, string $currentBranch, string $currentSha, int $commentCount): self
    {
        return new self(
            DivergenceDecisionKind::Show,
            state: DivergenceState::Diverged,
            context: [
                'target' => $target,
                'currentBranch' => $currentBranch,
                'currentSha' => $currentSha,
                'shortSha' => substr($currentSha, 0, 7),
                'commentCount' => $commentCount,
            ],
        );
    }

    /**
     * @return array{
     *     kind: string,
     *     state: ?string,
     *     autoFollowBranch: ?string,
     *     context: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->name,
            'state' => $this->state?->value,
            'autoFollowBranch' => $this->autoFollowBranch,
            'context' => $this->context,
        ];
    }
}
