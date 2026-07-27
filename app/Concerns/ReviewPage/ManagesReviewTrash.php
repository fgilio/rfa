<?php

declare(strict_types=1);

namespace App\Concerns\ReviewPage;

use App\Actions\CleanExpiredTrashAction;
use App\Actions\CreateCommentThreadSnapshotsAction;
use App\Actions\DeleteTrashedFileAction;
use App\Actions\DiscardFileChangesAction;
use App\Actions\RestoreDiscardedFileAction;
use App\Exceptions\GitCommandException;
use App\Support\DiffCacheKey;
use Flux\Flux;
use Livewire\Attributes\On;

/**
 * Discard, restore, and trash-list management for the review page.
 *
 * Discarding a working-tree file moves its changes into trash (an undoable
 * structural edit: the file leaves the diff list), restore brings them back,
 * and the trash list is reloaded after each. Working-directory mode only;
 * commit/range modes have no working tree to discard against.
 *
 * Component state read/written: $files, $comments, $reviewedFiles,
 * $trashedFiles, $repoPath, $projectId. Calls into the coordinator spine
 * (isCommitMode, buildDiffTarget, refreshFileList, saveSession, mergeComments)
 * and the render pipeline (skipRender, dispatch). undo() routes here for the
 * `discard` type but stays on the coordinator.
 */
trait ManagesReviewTrash
{
    #[On('discard-file')]
    public function discardFileChanges(string $fileId): void
    {
        if ($this->isCommitMode()) {
            return;
        }

        // In the entire-repo view every tracked file diffs as "added" from the
        // empty tree, so discarding one would run `git rm -f` on an otherwise
        // clean committed file. Refuse here regardless of which control fired.
        if ($this->isSinceBeginningView) {
            return;
        }

        $file = collect($this->files)->firstWhere('id', $fileId);
        if (! $file || $file['status'] === 'commented' || ($file['isExternal'] ?? false)) {
            return;
        }

        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();
        $commentSnapshots = app(CreateCommentThreadSnapshotsAction::class)->handle(
            $this->repoPath,
            $this->projectId ?: null,
            $fileComments,
        );

        try {
            $trashRecord = app(DiscardFileChangesAction::class)->handle(
                repoPath: $this->repoPath,
                path: $file['path'],
                status: $file['status'],
                projectId: $this->projectId,
                oldPath: $file['oldPath'] ?? null,
                isUntracked: $file['isUntracked'] ?? false,
                isSymlink: $file['isSymlink'] ?? false,
                comments: $commentSnapshots,
            );
        } catch (\Throwable $e) {
            $message = $e instanceof GitCommandException ? $e->stderr : $e->getMessage();
            Flux::toast(variant: 'danger', text: 'Discard failed for '.basename($file['path']).': '.$message);
            $this->skipRender();

            return;
        }

        // Remove comments for discarded file
        $this->comments = array_values(
            array_filter($this->comments, fn ($c) => $c['fileId'] !== $fileId)
        );

        // Invalidate every diff-cache variant for this file (base + :full-context).
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;
        DiffCacheKey::forget($projectKey, $fileId, $this->buildDiffTarget()->contextKey());

        unset($this->reviewedFiles[$file['path']]);

        $this->refreshFileList();
        $this->saveSession();
        $this->loadTrashedFiles();

        $commentCount = count($fileComments);
        $message = $commentCount > 0
            ? 'Discarded '.basename($file['path']).' - '.$commentCount.' comment'.($commentCount === 1 ? '' : 's').' removed'
            : 'Discarded '.basename($file['path']);
        $this->dispatch('undo-available', type: 'discard', payload: $trashRecord->id, message: $message);
        $this->dispatch('fingerprint-reset');
    }

    public function restoreDiscardedFile(int $trashId): void
    {
        try {
            $comments = app(RestoreDiscardedFileAction::class)->handle($trashId, $this->repoPath, $this->projectId);
        } catch (\Throwable $e) {
            $message = $e instanceof GitCommandException ? $e->stderr : $e->getMessage();
            Flux::toast(variant: 'danger', text: 'Restore failed: '.$message);
            $this->skipRender();

            return;
        }

        $this->mergeComments($comments);
        $this->refreshFileList();
        $this->saveSession();
        $this->loadTrashedFiles();

        Flux::toast(text: 'Changes restored');
    }

    public function permanentlyDeleteTrashed(int $trashId): void
    {
        app(DeleteTrashedFileAction::class)->handle($trashId, $this->projectId);
        $this->loadTrashedFiles();
    }

    private function loadTrashedFiles(): void
    {
        if ($this->isCommitMode()) {
            $this->trashedFiles = [];

            return;
        }

        $this->trashedFiles = app(CleanExpiredTrashAction::class)->handle($this->projectId);
    }
}
