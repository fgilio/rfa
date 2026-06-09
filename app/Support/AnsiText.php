<?php

declare(strict_types=1);

namespace App\Support;

final class AnsiText
{
    public const SEQUENCE_PATTERN = '/\x1b\[[0-9;]*m/';

    /** Remove every ANSI SGR color sequence from the given text. */
    public static function strip(string $text): string
    {
        return preg_replace(self::SEQUENCE_PATTERN, '', $text) ?? $text;
    }
}
