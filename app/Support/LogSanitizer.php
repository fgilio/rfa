<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Scrubs free-form process or exception text before it reaches a log payload.
 *
 * The wide-events protocol bans raw stderr, raw exception messages, and
 * avoidable absolute paths because they routinely leak repo internals, user
 * content, and home-directory paths into the local log. {@see summary()}
 * collapses such text into a bounded, single-line, home-scrubbed string that is
 * safe to attach to a warning while still aiding triage.
 */
final class LogSanitizer
{
    private const DEFAULT_MAX_LENGTH = 200;

    /**
     * Reduce raw process/exception text to a bounded, single-line, home-scrubbed
     * summary: ANSI color stripped, whitespace collapsed, the user's home
     * directory replaced with `~`, and the result truncated with an ellipsis
     * marker when it exceeds `$maxLength`.
     */
    public static function summary(string $raw, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        $text = AnsiText::strip($raw);
        $text = (string) preg_replace('/\s+/', ' ', $text);
        $text = self::scrubHomeDirectory(trim($text));

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $maxLength)).'…';
    }

    /**
     * Replace the current user's home directory with `~` so absolute paths under
     * it (the common case for a repo on disk) never reach the log verbatim.
     *
     * The match is anchored to a path boundary — end of string, or a character
     * that cannot continue a directory name — so a sibling like `/home/user2`
     * is left intact rather than mangled into `~2`.
     */
    private static function scrubHomeDirectory(string $text): string
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME');

        if (! is_string($home) || $home === '' || $home === '/') {
            return $text;
        }

        return (string) preg_replace('#'.preg_quote(rtrim($home, '/'), '#').'(?![\w-])#', '~', $text);
    }
}
