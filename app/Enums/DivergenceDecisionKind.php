<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The four mutually exclusive ways the review page can react to a HEAD poll.
 *
 * Separates the auto-follow command from the banner states so the page can
 * `match` on intent rather than re-deriving it from a state enum plus flags.
 */
enum DivergenceDecisionKind
{
    /** Leave the current divergence state untouched (transient git failure). */
    case Noop;

    /** Clear divergence: HEAD matches the target, or the user dismissed it. */
    case Aligned;

    /** Silently retarget the review to HEAD's branch (no comments at risk). */
    case AutoFollow;

    /** Surface a divergence banner in the carried state and context. */
    case Show;
}
