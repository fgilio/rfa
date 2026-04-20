<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Models\Comment;
use App\Models\ReviewedFile;
use App\Models\ReviewSession;
use App\Services\GitFileContentService;

final readonly class RestoreSessionAction
{
    public function __construct(
        private ResolveCommentAnchorAction $resolveCommentAnchor,
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
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

    private function resolveGlobalCommentRow(string $repoPath, ?int $projectId): ReviewSession
    {
        return ReviewSession::firstOrCreate(
            $projectId
                ? ['project_id' => $projectId]
                : ['project_id' => null, 'repo_path' => $repoPath],
            ['repo_path' => $repoPath],
        );
    }

    /** @return iterable<array<string, mixed>> */
    private function loadComments(string $repoPath, ?int $projectId): iterable
    {
        return Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->whereNull('submitted_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $c) => $c->toArray())
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $resolved
     * @param  array<int, array<string, mixed>>  $currentFiles
     * @return array<int, string>
     */
    private function collectOrphanedPaths(array $resolved, array $currentFiles): array
    {
        $presentPaths = collect($currentFiles)->pluck('path')->all();

        return collect($resolved)
            ->filter(fn (array $c) => ! in_array($c['file'], $presentPaths, true))
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

        $rightRef = $target->to() ?? GitFileContentService::WORKING_REF;
        $leftRef = $target->from();
        $reviewed = [];

        foreach ($currentFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! isset($hashesByPath[$path])) {
                continue;
            }

            $storedHashes = $hashesByPath[$path];

            // Legacy rows (indexed `reviewed_files` arrays or immutable-context sessions)
            // were migrated with an empty `content_hash`. Treat those as "reviewed
            // regardless of content" so migrated users keep their state.
            if (in_array('', $storedHashes, true)) {
                $reviewed[$path] = $this->gitFileContentService->hashAt($repoPath, $rightRef, $path) ?? '';

                continue;
            }

            // `ToggleReviewedAction` falls back to the left ref when the right side is
            // missing (deleted / left-only files). Check both sides on restore so the
            // reviewed flag survives for those files too.
            $rightHash = $this->gitFileContentService->hashAt($repoPath, $rightRef, $path);
            if ($rightHash !== null && in_array($rightHash, $storedHashes, true)) {
                $reviewed[$path] = $rightHash;

                continue;
            }

            $leftPath = $file['oldPath'] ?? $path;
            $leftHash = $this->gitFileContentService->hashAt($repoPath, $leftRef, $leftPath);
            if ($leftHash !== null && in_array($leftHash, $storedHashes, true)) {
                $reviewed[$path] = $leftHash;
            }
        }

        return $reviewed;
    }
}
