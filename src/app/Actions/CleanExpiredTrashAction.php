<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TrashedFile;
use Illuminate\Support\Facades\Storage;

final readonly class CleanExpiredTrashAction
{
    /**
     * Clean expired trash entries and return active ones for the given project.
     *
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $projectId): array
    {
        // Clean expired entries (storage files first, then bulk delete)
        $expired = TrashedFile::where('project_id', $projectId)
            ->where('expires_at', '<', now())
            ->get();

        if ($expired->isNotEmpty()) {
            $expired->each(fn (TrashedFile $r) => Storage::delete("trash/{$r->id}"));
            TrashedFile::whereIn('id', $expired->pluck('id'))->delete();
        }

        // Return active entries
        return TrashedFile::where('project_id', $projectId)
            ->active()
            ->latest()
            ->get()
            ->toArray();
    }
}
