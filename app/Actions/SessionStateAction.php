<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Enums\DiffSide;
use App\Models\Comment;
use App\Models\ReviewedFile;
use App\Models\ReviewSession;
use App\Services\GitFileContentService;

/**
 * Owns both halves of review-session persistence:
 *
 * - `handle()`         hydrates comments, reviewed files, orphaned paths, and
 *                      the free-form repo-level note into the shape the
 *                      review-page needs to render (the primary action entry
 *                      point).
 * - `saveGlobalNote()` writes the repo-scoped `global_comment` back. Per-
 *                      comment and reviewed-file state live row-by-row in
 *                      their own tables and are not touched here.
 */
final readonly class SessionStateAction
{
    public function __construct(
        private ResolveCommentAnchorAction $resolveCommentAnchor,
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * Hydrate the review-page's in-memory session snapshot for a given diff target.
     *
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array{comments: array<int, array<string, mixed>>, reviewedFiles: array<string, string>, globalComment: string, orphanedPaths: array<int, string>}
     */
    public function handle(string $repoPath, array $currentFiles, ?int $projectId = null, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $session = $this->resolveGlobalCommentRow($repoPath, $projectId);

        $rawComments = $this->loadComments($repoPath, $projectId);
        $resolved = $this->resolveCommentAnchor->handle($repoPath, $rawComments, $currentFiles, $target);

        $orphanedPaths = $this->collectOrphanedPaths($resolved, $currentFiles);

        $reviewedFiles = $this->resolveReviewedFiles($repoPath, $projectId, $currentFiles, $target);

        return [
            'comments' => $resolved,
            'reviewedFiles' => $reviewedFiles,
            'globalComment' => (string) ($session->global_comment ?? ''),
            'orphanedPaths' => $orphanedPaths,
        ];
    }

    public function saveGlobalNote(string $repoPath, string $globalComment, ?int $projectId = null): void
    {
        ReviewSession::updateOrCreate(
            $projectId
                ? ['project_id' => $projectId]
                : ['project_id' => null, 'repo_path' => $repoPath],
            [
                'repo_path' => $repoPath,
                'global_comment' => $globalComment,
            ]
        );
    }

    private function resolveGlobalCommentRow(string $repoPath, ?int $projectId): ReviewSession
    {
        return ReviewSession::firstOrCreate(
            $projectId
                ? ['project_id' => $projectId]
                : ['project_id' => null, 'repo_path' => $repoPath],
            ['repo_path' => $repoPath],
        );
    }

    /** @return list<array<string, mixed>> */
    private function loadComments(string $repoPath, ?int $projectId): array
    {
        return Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->fromReview()
            ->whereNull('submitted_at')
            ->with('replies')
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolved
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array<int, string>
     */
    private function collectOrphanedPaths(array $resolved, array $currentFiles): array
    {
        $presentPaths = collect($currentFiles)->pluck('path')->flip();

        return collect($resolved)
            ->reject(fn (array $c) => $presentPaths->has($c['file']))
            ->pluck('file')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array<string, string>
     */
    private function resolveReviewedFiles(string $repoPath, ?int $projectId, array $currentFiles, DiffTarget $target): array
    {
        /** @var array<string, array<int, string>> $hashesByPath */
        $hashesByPath = ReviewedFile::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->get(['file_path', 'content_hash'])
            ->groupBy('file_path')
            ->map(fn ($rows) => $rows->pluck('content_hash')->all())
            ->all();

        $reviewed = [];

        foreach ($currentFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! isset($hashesByPath[$path])) {
                continue;
            }

            $storedHashes = $hashesByPath[$path];

            // External files live outside the repo, so their reviewed-anchor
            // hash must come from the absolute on-disk path rather than a
            // git ref. Mismatch → un-review, matching the diff-anchor model.
            if (($file['isExternal'] ?? false) && ! empty($file['externalAbsolutePath'])) {
                $currentHash = $this->gitFileContentService->hashForSource($repoPath, FileSourceSpec::absolute((string) $file['externalAbsolutePath']));
                if ($currentHash !== null && in_array($currentHash, $storedHashes, true)) {
                    $reviewed[$path] = $currentHash;
                }

                continue;
            }

            $rightSource = FileSourceSpec::forSide($target, DiffSide::Right, $path);

            // Legacy rows (indexed `reviewed_files` arrays or immutable-context sessions)
            // were migrated with an empty `content_hash`. Treat those as "reviewed
            // regardless of content" so migrated users keep their state.
            if (in_array('', $storedHashes, true)) {
                $reviewed[$path] = $this->gitFileContentService->hashForSource($repoPath, $rightSource) ?? '';

                continue;
            }

            // `ToggleReviewedAction` falls back to the left ref when the right side is
            // missing (deleted / left-only files). Check both sides on restore so the
            // reviewed flag survives for those files too.
            $rightHash = $this->gitFileContentService->hashForSource($repoPath, $rightSource);
            if ($rightHash !== null && in_array($rightHash, $storedHashes, true)) {
                $reviewed[$path] = $rightHash;

                continue;
            }

            $leftSource = FileSourceSpec::forSide($target, DiffSide::Left, $path, $this->oldPathOf($file));
            $leftHash = $this->gitFileContentService->hashForSource($repoPath, $leftSource);
            if ($leftHash !== null && in_array($leftHash, $storedHashes, true)) {
                $reviewed[$path] = $leftHash;
            }
        }

        return $reviewed;
    }

    /**
     * The pre-rename path a left-side anchor lives at, or null when the file
     * was not renamed.
     *
     * @param  array<string, mixed>  $file
     */
    private function oldPathOf(array $file): ?string
    {
        $oldPath = $file['oldPath'] ?? null;

        return is_string($oldPath) ? $oldPath : null;
    }
}
