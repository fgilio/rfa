<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ContextCommentRejection;
use DomainException;

/**
 * Raised by ContextCommentWorkflowAction::handle() when a payload
 * cannot be turned into a comment for a programmer-level reason
 * (unknown file, malformed side/line combo, out-of-bounds line, etc).
 *
 * The Livewire page catches this, logs the named reason, and treats
 * the action as a no-op so a stale screen never crashes the renderer.
 * Tests assert the specific reason via $exception->reason instead of
 * inspecting a bare null return.
 */
class ContextCommentRejectedException extends DomainException
{
    public function __construct(public readonly ContextCommentRejection $reason)
    {
        parent::__construct("Context comment payload rejected: {$reason->value}");
    }
}
