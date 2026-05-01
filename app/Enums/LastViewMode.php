<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Per-project sticky page selection. Saved on every page mount; consulted
 * from project-picker, startup-route, and the home redirect so re-entering
 * a project lands the user on whichever page they last had open.
 */
enum LastViewMode: string
{
    case Review = 'review';
    case Context = 'context';

    public static function fromNullable(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
