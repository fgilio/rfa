<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a diff load ended. Exactly one case describes any loaded diff, so cache
 * readers and the renderer branch on this rather than on a combination of
 * flags.
 */
enum DiffLoadOutcome: string
{
    /** Git produced a patch and the parser turned it into hunks. */
    case Loaded = 'loaded';

    /** Git produced no output: the file has no changes against the target. */
    case Empty = 'empty';

    /** The patch exceeded the configured byte cap and was never parsed. */
    case TooLarge = 'too-large';

    /** Git produced output the parser could not turn into a file diff. */
    case Unparsable = 'unparsable';

    /** The git process failed. The next read retries instead of serving this. */
    case TransientError = 'transient-error';

    /**
     * A transient error says nothing about the file, only about one failed
     * process, so storing it would serve the failure for the whole TTL.
     */
    public function isCacheable(): bool
    {
        return $this !== self::TransientError;
    }
}
