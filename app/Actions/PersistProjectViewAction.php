<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Models\ReviewSession;

/**
 * Saves "the page the user is currently looking at" so that re-entry — via
 * the project picker, startup redirect, or menu deep-link — can put them back
 * on the same surface. Read side: {@see ResolveProjectEntryUrlAction}.
 *
 * SinceBase intentionally does not persist `from`/`to`: the resolver
 * re-resolves the merge-base at restore time so the view follows base-branch
 * advancement. Storing the at-save SHA would be both unused and misleading.
 */
final readonly class PersistProjectViewAction
{
    public function handle(
        int $projectId,
        string $repoPath,
        LastViewMode $mode,
        ?LastViewKind $kind = null,
        ?string $from = null,
        ?string $to = null,
    ): void {
        $persistsRefs = $mode === LastViewMode::Review
            && $kind !== null
            && $kind !== LastViewKind::SinceBase;

        ReviewSession::updateOrCreate(
            ReviewSession::lookupKey($repoPath, $projectId ?: null),
            [
                'repo_path' => $repoPath,
                'last_view_mode' => $mode,
                'last_view_kind' => $mode === LastViewMode::Context ? null : $kind,
                'last_view_from' => $persistsRefs ? $from : null,
                'last_view_to' => $persistsRefs ? $to : null,
            ]
        );
    }
}
