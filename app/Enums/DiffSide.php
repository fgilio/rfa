<?php

declare(strict_types=1);

namespace App\Enums;

enum DiffSide: string
{
    case Left = 'left';
    case Right = 'right';
    case File = 'file';
}
