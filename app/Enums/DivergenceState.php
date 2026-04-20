<?php

declare(strict_types=1);

namespace App\Enums;

enum DivergenceState: string
{
    case Aligned = 'aligned';
    case Diverged = 'diverged';
    case Detached = 'detached';
    case MissingTarget = 'missing_target';
}
