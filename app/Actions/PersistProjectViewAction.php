<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use App\Models\ReviewSession;

/**
 * Persists "the page the user is currently looking at" for a project so that
 * re-entry (project picker, startup redirect, menu deep-link) can put them
 * back on the same surface.
 *
 * Save-side only — the matching read happens in {@see ResolveProjectEntryUrlAction}.
 *
 * Mode and kind are orthogonal:
 *  - {@see LastViewMode::Context} stores nothing else (kind/from/to are nulled)
 *  - {@see LastViewMode::Review} stores the diff selection via $kind/$from/$to
 *
 * `SinceBase` is saved as semantic intent rather than the resolved SHA so the
 * restore side can re-resolve against the current merge-base. The other four
 * review kinds round-trip the literal refs.
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
        $isContext = $mode === LastViewMode::Context;

        ReviewSession::updateOrCreate(
            ReviewSession::lookupKey($repoPath, $projectId ?: null),
            [
                'repo_path' => $repoPath,
                'last_view_mode' => $mode,
                'last_view_kind' => $isContext ? null : $kind,
                'last_view_from' => $isContext ? null : $from,
                'last_view_to' => $isContext ? null : $to,
            ]
        );
    }
}
