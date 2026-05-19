<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Utilities for reducing arbitrary text and absolute paths to bounded,
 * privacy-preserving values suitable for wide-event log payloads. See
 * `.claude/skills/wide-events/SKILL.md` for the rules these helpers exist
 * to satisfy.
 */
final class LogSanitizer
{
    private const MAX_SUMMARY_LENGTH = 200;

    /**
     * First non-empty line of $raw, trimmed and capped, with an ellipsis when
     * truncated. Multi-line stderr and exception messages collapse to a single
     * short line so logs stay queryable.
     */
    public static function summarize(?string $raw, int $max = self::MAX_SUMMARY_LENGTH): string
    {
        if ($raw === null) {
            return '';
        }

        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            return mb_strimwidth($trimmed, 0, $max, '...');
        }

        return '';
    }

    /**
     * Stable hash of an absolute filesystem path. Same path always yields the
     * same hash within a process, letting reviewers correlate events for the
     * same repo without putting the absolute path itself in the log.
     */
    public static function hashPath(string $path): string
    {
        return hash('xxh128', $path);
    }
}
