<?php

declare(strict_types=1);

namespace App\Enums;

enum BranchBaseState: string
{
    /** Base configured, resolved, and HEAD is ahead of it - ready to apply. */
    case Ready = 'ready';

    /** No base branch configured for this project. */
    case NotConfigured = 'not_configured';

    /** Base resolved but HEAD has no commits ahead of it. */
    case UpToDate = 'up_to_date';

    /** Base branch name configured but the ref isn't present locally. */
    case MissingRef = 'missing_ref';

    /** HEAD is currently on the configured base branch - comparing to itself is nonsense. */
    case OnBaseBranch = 'on_base_branch';
}
