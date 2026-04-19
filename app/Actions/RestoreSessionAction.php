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
        $query = Comment::query()->whereNull('submitted_at');
        $query = $projectId
            ? $query->where('project_id', $projectId)
            : $query->whereNull('project_id')->where('repo_path', $repoPath);

        return $query->orderBy('created_at')->get()->map(fn (Comment $c) => $c->toArray())->all();
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
        $query = ReviewedFile::query();
        $query = $projectId
            ? $query->where('project_id', $projectId)
            : $query->whereNull('project_id')->where('repo_path', $repoPath);

        /** @var array<string, array<int, string>> $hashesByPath */
        $hashesByPath = $query->get(['file_path', 'content_hash'])
            ->groupBy('file_path')
            ->map(fn ($rows) => $rows->pluck('content_hash')->all())
            ->all();

        $ref = $target->to() ?? GitFileContentService::WORKING_REF;
        $reviewed = [];

        foreach ($currentFiles as $file) {
            $path = (string) ($file['path'] ?? '');
            if ($path === '' || ! isset($hashesByPath[$path])) {
                continue;
            }

            $currentHash = $this->gitFileContentService->hashAt($repoPath, $ref, $path) ?? '';

            if ($currentHash !== '' && in_array($currentHash, $hashesByPath[$path], true)) {
                $reviewed[$path] = $currentHash;
            }
        }

        return $reviewed;
    }
}
