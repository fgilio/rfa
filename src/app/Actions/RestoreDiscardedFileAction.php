<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TrashedFile;
use App\Support\PathGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final readonly class RestoreDiscardedFileAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $trashId, string $repoPath, int $projectId): array
    {
        $trashed = TrashedFile::where('id', $trashId)
            ->where('project_id', $projectId)
            ->active()
            ->firstOrFail();

        PathGuard::assertRelative($trashed->file_path);

        if ($trashed->old_path !== null) {
            PathGuard::assertRelative($trashed->old_path);
        }

        $fullPath = $repoPath.'/'.$trashed->file_path;
        $content = Storage::exists("trash/{$trashed->id}")
            ? Storage::get("trash/{$trashed->id}")
            : null;

        match ($trashed->file_status) {
            'deleted' => File::delete($fullPath),
            'renamed' => $this->restoreRenamed($trashed, $repoPath, $fullPath, $content),
            default => $this->writeContent($trashed, $fullPath, $content),
        };

        $comments = $trashed->comments ?? [];

        // Clean up storage and delete record
        Storage::delete("trash/{$trashed->id}");
        $trashed->delete();

        return $comments;
    }

    private function writeContent(TrashedFile $trashed, string $fullPath, ?string $content): void
    {
        if ($content === null) {
            return;
        }

        $dir = dirname($fullPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if ($trashed->is_symlink) {
            if (File::exists($fullPath) || is_link($fullPath)) {
                File::delete($fullPath);
            }
            symlink($content, $fullPath);
        } else {
            File::put($fullPath, $content);
        }
    }

    private function restoreRenamed(TrashedFile $trashed, string $repoPath, string $fullPath, ?string $content): void
    {
        $this->writeContent($trashed, $fullPath, $content);

        // Delete the restored old_path (git restore brought it back)
        if ($trashed->old_path) {
            $oldFullPath = $repoPath.'/'.$trashed->old_path;
            if (File::exists($oldFullPath)) {
                File::delete($oldFullPath);
            }
        }
    }
}
