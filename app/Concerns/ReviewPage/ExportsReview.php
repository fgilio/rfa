<?php

declare(strict_types=1);

namespace App\Concerns\ReviewPage;

use App\Actions\ExportReviewAction;
use App\Actions\ExportReviewSnapshotAction;
use App\DTOs\ReviewFilePair;
use Flux\Flux;

/**
 * Review finalization and export for the review page.
 *
 * Submitting formats the non-draft comments into a review, copies it to the
 * clipboard, and drops the submitted comments from the pool (drafts and
 * unplaceable comments stay). Snapshot export and copy-visible-paths are
 * read-only side exports. startNewReview clears the receipt so the editor
 * returns.
 *
 * Component state read/written: $comments, $globalComment, $files,
 * $reviewedFiles, $repoPath, $projectName, $submissionReceipt. Calls
 * into the coordinator (saveSession, buildDiffTarget, scanReviewFiles,
 * dispatchFileComments, the reviewState computed) and the render pipeline
 * (skipRender, dispatch). submitReview renders (it swaps the submit bar);
 * copyVisiblePaths skips render since the clipboard write is client-side.
 */
trait ExportsReview
{
    public function submitReview(): void
    {
        $this->saveSession();

        $target = $this->buildDiffTarget();
        $finalizedComments = array_values(array_filter($this->comments, fn ($c) => ! ($c['isDraft'] ?? false)));

        $result = app(ExportReviewAction::class)->handle($this->repoPath, $finalizedComments, $this->globalComment, $this->files, $target);

        $this->submissionReceipt = ['path' => $result['md'], 'clipboard' => $result['clipboard']];

        $this->scanReviewFiles();

        Flux::toast(variant: 'success', heading: 'Review submitted', text: $result['clipboard']);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard']);

        // Never drop a comment silently: if the anchor resolver couldn't place some
        // comments against this diff, they stay in the pool and the user is told.
        $excludedCount = count($result['excludedComments'] ?? []);
        if ($excludedCount > 0) {
            Flux::toast(
                variant: 'warning',
                heading: $excludedCount === 1 ? '1 comment not included' : "{$excludedCount} comments not included",
                text: "Their anchor could not be placed in this diff. They're kept for a later submit.",
            );
        }

        // Only drop comments the export actually submitted; drafts and out-of-scope
        // comments (e.g. hash-anchored from another selection) stay in the pool.
        $submittedIds = $result['submittedIds'];
        $affectedFileIds = collect($this->comments)
            ->whereIn('id', $submittedIds)
            ->pluck('fileId')
            ->unique();
        $this->comments = array_values(array_filter(
            $this->comments,
            fn ($c) => ! in_array($c['id'], $submittedIds, true),
        ));
        $this->globalComment = '';
        $this->saveSession();

        $affectedFileIds->each(fn (string $fileId) => $this->dispatchFileComments($fileId));
    }

    public function exportSnapshot(): void
    {
        $this->saveSession();

        $result = app(ExportReviewSnapshotAction::class)->handle(
            repoPath: $this->repoPath,
            files: $this->files,
            comments: $this->comments,
            globalComment: $this->globalComment,
            reviewedFiles: $this->reviewedFiles,
            target: $this->buildDiffTarget(),
            sourceLabel: $this->projectName !== '' ? $this->projectName : basename($this->repoPath),
        );

        Flux::toast(variant: 'success', heading: 'Snapshot exported', text: $result['json']);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard'], toast: 'Snapshot path copied');
    }

    /**
     * Copy the currently visible (filtered) file paths to the clipboard as bare
     * names, repo-relative paths, or absolute paths. The visible set is derived
     * server-side, so a filtered copy always matches what the user sees without
     * the client reconstructing the list from the DOM.
     */
    public function copyVisiblePaths(string $kind = 'relative'): void
    {
        $this->skipRender();

        $files = collect($this->reviewState->visibleFiles)
            ->filter(fn (array $file): bool => ! empty($file['path']))
            ->values();

        if ($files->isEmpty()) {
            return;
        }

        $repoPath = rtrim($this->repoPath, '/');

        $lines = $files->map(fn (array $file): string => match ($kind) {
            'name' => basename($file['path']),
            'full' => ($file['isExternal'] ?? false) && ! empty($file['externalAbsolutePath'])
                ? (string) $file['externalAbsolutePath']
                : ($repoPath === '' ? $file['path'] : $repoPath.'/'.$file['path']),
            default => $file['path'],
        });

        $noun = match ($kind) {
            'name' => 'file name',
            'full' => 'full path',
            default => 'relative path',
        };
        $count = $lines->count();
        $toast = $count === 1 ? "Copied {$noun}" : "Copied {$count} {$noun}s";

        $this->dispatch('copy-to-clipboard', text: $lines->implode("\n"), toast: $toast);
    }

    public function startNewReview(): void
    {
        $this->resetSubmittedState();
    }

    /**
     * Return the submit bar to its editing state and forget the review file it
     * referenced. Shared by startNewReview and by deleting the submitted review.
     */
    private function resetSubmittedState(): void
    {
        $this->submissionReceipt = null;
    }

    /**
     * Basename of the review file the "Review submitted" bar points at, derived
     * from the receipt so it can never disagree with what the bar is showing.
     */
    private function submittedReviewBasename(): ?string
    {
        return $this->submissionReceipt === null
            ? null
            : ReviewFilePair::extractBasename($this->submissionReceipt['path']);
    }
}
