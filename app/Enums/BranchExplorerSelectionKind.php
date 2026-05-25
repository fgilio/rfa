<?php

declare(strict_types=1);

namespace App\Enums;

enum BranchExplorerSelectionKind: string
{
    case Noop = 'noop';
    case Navigate = 'navigate';
    case Error = 'error';
    case Stale = 'stale';
}
