<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment;
use App\DTOs\DiffTarget;
use App\Models\Comment as CommentModel;
use App\Services\CommentExporter;
use App\Services\GitFileContentService;

final readonly class ExportReviewAction
{
    public function __construct(
        private BuildDiffContextAction $buildDiffContextAction,
        private CommentExporter $commentExporter,
        private EnsureRfaGitExcludeAction $ensureGitExclude,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments  Currently-loaded, view-ready comments.
     * @param  array<int, array<string, mixed>>  $files  Files in the current diff.
     * @return array{json: string, md: string, clipboard: string, submittedIds: array<int, string>}
     */
    public function handle(string $repoPath, array $comments, string $globalComment, array $files, ?DiffTarget $target = null): array
    {
        $inScope = $this->filterInScope($comments, $target);

        $commentDTOs = array_map(fn ($c) => Comment::fromArray($c), $inScope);

        $diffContext = $this->buildDiffContextAction->handle($repoPath, $inScope, $files);

        $result = $this->commentExporter->export($repoPath, $commentDTOs, $globalComment, $diffContext);

        $ids = array_column($inScope, 'id');
        if ($ids !== []) {
            CommentModel::whereIn('id', $ids)->update(['submitted_at' => now()]);
        }

        $this->ensureGitExclude->handle($repoPath);

        return [...$result, 'submittedIds' => $ids];
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>
     */
    private function filterInScope(array $comments, ?DiffTarget $target): array
    {
        if ($target === null) {
            return $comments;
        }

        $scopeRef = $target->to() ?? GitFileContentService::WORKING_REF;

        return array_values(array_filter(
            $comments,
            fn (array $c) => ($c['originRef'] ?? GitFileContentService::WORKING_REF) === $scopeRef,
        ));
    }
}
