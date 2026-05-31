<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\TrashedFile;
use App\Services\GitProcessService;
use App\Support\PathGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final readonly class DiscardFileChangesAction
{
    public function __construct(
        private GitProcessService $git,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments
     */
    public function handle(
        string $repoPath,
        string $path,
        string $status,
        int $projectId,
        ?string $oldPath = null,
        bool $isUntracked = false,
        bool $isSymlink = false,
        array $comments = [],
    ): TrashedFile {
        PathGuard::assertRelative($path);

        if ($oldPath !== null) {
            PathGuard::assertRelative($oldPath);
        }

        // Save content to trash before running git commands
        $trashRecord = TrashedFile::create([
            'project_id' => $projectId,
            'file_path' => $path,
            'file_status' => $status,
            'old_path' => $oldPath,
            'is_untracked' => $isUntracked,
            'is_symlink' => $isSymlink,
            'comments' => ! empty($comments) ? $comments : null,
            'expires_at' => now()->addMinutes(30),
        ]);

        // Save file content (if the file exists in working tree). Check is_link
        // FIRST: File::exists()/file_exists() follows the link and returns false
        // for a DANGLING symlink, which would skip the save entirely and make the
        // "undoable" discard silently unrecoverable. readlink reads the target
        // string without requiring the target to exist.
        $fullPath = $repoPath.'/'.$path;

        if (is_link($fullPath)) {
            Storage::put($trashRecord->blobPath(), (string) readlink($fullPath));
        } elseif (File::exists($fullPath)) {
            Storage::put($trashRecord->blobPath(), File::get($fullPath));
        }

        // Run the discard operation
        try {
            $this->executeDiscard($repoPath, $path, $status, $oldPath, $isUntracked, $fullPath);
        } catch (\Throwable $e) {
            // Roll back: deleting the record also purges its blob (TrashedFile::deleting).
            $trashRecord->delete();
            throw $e;
        }

        return $trashRecord;
    }

    private function executeDiscard(
        string $repoPath,
        string $path,
        string $status,
        ?string $oldPath,
        bool $isUntracked,
        string $fullPath,
    ): void {
        match (true) {
            $status === 'added' && $isUntracked => File::delete($fullPath),
            $status === 'added' => $this->git->run($repoPath, ['rm', '-f', '--', $path]),
            $status === 'renamed' => $this->git->run($repoPath, ['restore', '--source=HEAD', '--staged', '--worktree', '--', $oldPath, $path]),
            default => $this->git->run($repoPath, ['restore', '--source=HEAD', '--staged', '--worktree', '--', $path]),
        };
    }
}
