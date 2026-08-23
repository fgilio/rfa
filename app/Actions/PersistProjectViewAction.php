<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\SavedView;
use App\Models\ReviewSession;

/**
 * Saves "the page the user is currently looking at" so that re-entry — via
 * the project picker, startup redirect, or menu deep-link — can put them back
 * on the same surface. Read side: {@see ResolveProjectEntryUrlAction}.
 *
 * Which columns a view fills is the {@see SavedView} factories' call, not this
 * action's: Context clears the review columns, SinceBase stores no refs (the
 * resolver re-resolves the merge-base at restore time), and the rest carry the
 * refs their factory accepted.
 */
final readonly class PersistProjectViewAction
{
    public function handle(int $projectId, string $repoPath, SavedView $view): void
    {
        ReviewSession::updateOrCreate(
            ReviewSession::lookupKey($repoPath, $projectId ?: null),
            [
                'repo_path' => $repoPath,
                ...$view->toArray(),
            ]
        );
    }
}
