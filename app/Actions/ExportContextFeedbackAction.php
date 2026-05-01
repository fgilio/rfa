<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentDTO;
use App\Enums\CommentExportKind;
use App\Models\Comment;
use App\Services\CommentExporter;

/**
 * Export context-file comments to .rfa/ as a Claude-ready prompt and stamp
 * each row as submitted. Sibling to ExportReviewAction, but uses the
 * "context-file" exporter kind so the resulting markdown carries the
 * "improve this CLAUDE.md" intro instead of the code-review intro.
 */
final readonly class ExportContextFeedbackAction
{
    public function __construct(
        private CommentExporter $commentExporter,
        private EnsureRfaGitExcludeAction $ensureGitExclude,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments  View-state context-file comments.
     * @return array{md: string, clipboard: string, submittedIds: array<int, string>}
     */
    public function handle(string $repoPath, ?int $projectId, array $comments, string $globalComment): array
    {
        $finalized = array_values(array_filter(
            $comments,
            fn (array $c): bool => ! ($c['isDraft'] ?? false),
        ));

        $commentDTOs = array_map(fn ($c) => CommentDTO::fromArray($c), $finalized);

        $result = $this->commentExporter->export(
            $repoPath,
            $commentDTOs,
            $globalComment,
            diffContext: [],
            kind: CommentExportKind::ContextFile,
        );

        $ids = array_column($finalized, 'id');
        if ($ids !== []) {
            Comment::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->fromContext()
                ->unsubmitted()
                ->whereIn('id', $ids)
                ->update(['submitted_at' => now()]);
        }

        $this->ensureGitExclude->handle($repoPath);

        return [...$result, 'submittedIds' => $ids];
    }
}
