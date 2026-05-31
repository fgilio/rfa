<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TrashedFile;

final readonly class DeleteTrashedFileAction
{
    public function handle(int $trashId, int $projectId): void
    {
        $trashed = TrashedFile::where('id', $trashId)
            ->where('project_id', $projectId)
            ->first();

        if (! $trashed) {
            return;
        }

        // Deleting the record also purges its blob (TrashedFile::deleting).
        $trashed->delete();
    }
}
