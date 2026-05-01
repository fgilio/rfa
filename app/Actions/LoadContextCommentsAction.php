<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentDTO;
use App\Enums\DiffSide;
use App\Models\Comment;

/**
 * Load context-file comments for the Context page in the view-state shape
 * the diff-file Livewire component expects. Mirrors how SessionStateAction
 * hydrates review-page comments, but skips anchor resolution and reviewed-
 * file machinery — context files always render as "untracked, file vs.
 * /dev/null", so no anchor drift is possible.
 */
final readonly class LoadContextCommentsAction
{
    /** @return array<int, array<string, mixed>> */
    public function handle(string $repoPath, ?int $projectId): array
    {
        return Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->where('origin_ref', ContextCommentWorkflowAction::ORIGIN_REF)
            ->whereNull('submitted_at')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $row): array => (new CommentDTO(
                id: $row->id,
                fileId: 'ctx-'.hash('xxh128', $row->file_path),
                file: $row->file_path,
                side: DiffSide::from($row->side),
                startLine: $row->start_line,
                endLine: $row->end_line,
                body: $row->body,
                originRef: $row->origin_ref,
                fileContentHash: $row->file_content_hash,
                lineSnippet: $row->line_snippet,
                isDraft: $row->is_draft,
                // The query already filters submitted_at IS NULL, so this is
                // always null at the boundary — no point round-tripping it.
                submittedAt: null,
            ))->toArray())
            ->all();
    }
}
