<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why ContextCommentWorkflowAction::handle() refused to write a row.
 *
 * Only programmer/payload-level rejections live here. An empty body is
 * a soft no-op (user pressed save on an empty textarea) and stays a
 * silent return. Everything in this enum represents a stale or
 * malformed Livewire payload and is raised as a
 * ContextCommentRejectedException so the boundary is honest.
 */
enum ContextCommentRejection: string
{
    case UnknownFileId = 'unknown-file-id';
    case InvalidSide = 'invalid-side';
    case LeftSideNotAllowed = 'left-side-not-allowed';
    case FileSideWithLines = 'file-side-with-lines';
    case LineLevelMissingStart = 'line-level-missing-start';
    case LineRangeReversed = 'line-range-reversed';
    case LineOutOfFileBounds = 'line-out-of-file-bounds';
}
