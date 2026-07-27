<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentSurface: string
{
    case Review = 'review';
    case Context = 'context';
}
