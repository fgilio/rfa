<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TrashedFile;

final readonly class CleanExpiredTrashAction
{
    /**
     * Clean expired trash entries and return active ones for the given project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $projectId): array
    {
        // Delete per-model (not a bulk query-builder delete) so each row's
        // TrashedFile::deleting event fires and purges its content blob.
        TrashedFile::where('project_id', $projectId)
            ->where('expires_at', '<', now())
            ->get()
            ->each(fn (TrashedFile $trashedFile) => $trashedFile->delete());

        // Return active entries
        return TrashedFile::where('project_id', $projectId)
            ->active()
            ->latest()
            ->get()
            ->toArray();
    }
}
