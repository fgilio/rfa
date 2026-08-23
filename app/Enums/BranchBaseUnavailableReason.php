<?php

declare(strict_types=1);

namespace App\Enums;

enum BranchBaseUnavailableReason: string
{
    case UnrelatedHistory = 'unrelated_history';

    case CommandFailed = 'command_failed';
}
