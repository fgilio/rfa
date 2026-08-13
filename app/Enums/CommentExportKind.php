<?php

declare(strict_types=1);

namespace App\Enums;

enum CommentExportKind: string
{
    /** Default review-feedback prompt: code-diff comments addressed in place. */
    case Review = 'review';

    /** Context-file feedback: rewrites to an agent context file from comments. */
    case ContextFile = 'context-file';
}
