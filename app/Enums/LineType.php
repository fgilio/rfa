<?php

declare(strict_types=1);

namespace App\Enums;

enum LineType: string
{
    case Add = 'add';
    case Remove = 'remove';
    case Context = 'context';
}
