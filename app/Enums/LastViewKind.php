<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Shape of the review-page diff selection persisted alongside {@see LastViewMode::Review}.
 *
 * `SinceBase` is stored as semantic intent (not a frozen SHA): the entry-URL
 * resolver re-resolves the merge-base against the current state of the repo
 * so the restored view follows base-branch advancement instead of pinning a
 * stale commit.
 *
 * The other four kinds round-trip the literal `from` / `to` refs and validate
 * them at restore time. Anything that no longer resolves falls back to the
 * working-tree default.
 */
enum LastViewKind: string
{
    case WorkingTree = 'working_tree';
    case SinceBase = 'since_base';
    case Commit = 'commit';
    case Range = 'range';
    case RangeToWorking = 'range_to_working';

    public static function fromNullable(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
