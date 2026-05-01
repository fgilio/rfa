<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * `SinceBase` is stored as semantic intent: the entry-URL resolver re-resolves
 * the merge-base at restore time so the view follows base-branch advancement
 * instead of pinning a stale commit. The other four kinds round-trip the
 * literal `from` / `to` refs and validate them against the live repo.
 */
enum LastViewKind: string
{
    case WorkingTree = 'working_tree';
    case SinceBase = 'since_base';
    case Commit = 'commit';
    case Range = 'range';
    case RangeToWorking = 'range_to_working';
}
