<?php

declare(strict_types=1);

namespace App\Enums;

enum GitRef: string
{
    /** Sentinel ref for the working copy on disk (vs a committed tree). */
    case Working = 'working';

    /** Sentinel ref for files outside the repo, mounted via Project::external_paths. */
    case External = 'external';
}
