<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentAuthorType: string
{
    case Human = 'human';
    case Agent = 'agent';
}
