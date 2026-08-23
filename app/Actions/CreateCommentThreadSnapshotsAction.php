<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentData;
use App\DTOs\CommentReply as CommentReplyData;
use App\DTOs\CommentThreadSnapshot;
use App\Enums\AnchorStatus;
use App\Enums\CommentSurface;
use App\Enums\DiffSide;
use App\Models\Comment;
use Illuminate\Support\Carbon;

final readonly class CreateCommentThreadSnapshotsAction
{
    /**
     * Load database-authoritative roots and replies before a cascading delete.
     *
     * Every field comes from the stored row. The view comment contributes only
     * what the database doesn't know: which file card the comment is rendered
     * on, and where the anchor resolver placed it for the diff on screen.
     *
     * @param  list<array<string, mixed>>  $comments
     * @return list<array<string, mixed>>
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        array $comments,
        ?CommentSurface $surface = null,
    ): array {
        $commentsById = collect($comments)->keyBy(
            fn (array $comment): string => (string) ($comment['id'] ?? ''),
        );

        if ($commentsById->isEmpty()) {
            return [];
        }

        $query = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->whereKey($commentsById->keys())
            ->with('replies');

        if ($surface !== null) {
            $query->fromSurface($surface);
        }

        $storedComments = $query
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return $commentsById
            ->map(function (array $viewComment, string $commentId) use ($storedComments): ?array {
                $storedComment = $storedComments->get($commentId);

                if (! $storedComment instanceof Comment) {
                    return null;
                }

                $storedSide = DiffSide::from((string) $storedComment->side);

                $comment = new CommentData(
                    id: $storedComment->id,
                    fileId: (string) ($viewComment['fileId'] ?? ''),
                    file: $storedComment->file_path,
                    side: $storedSide,
                    startLine: $storedComment->start_line,
                    endLine: $storedComment->end_line,
                    body: $storedComment->body,
                    originRef: $storedComment->origin_ref,
                    fileContentHash: $storedComment->file_content_hash,
                    lineSnippet: $storedComment->line_snippet,
                    isDraft: (bool) $storedComment->is_draft,
                    submittedAt: $this->dateOrNull($storedComment->getAttribute('submitted_at')),
                    anchorStatus: AnchorStatus::tryFrom((string) ($viewComment['anchorStatus'] ?? ''))
                        ?? AnchorStatus::Placed,
                    originalSide: DiffSide::tryFrom((string) ($viewComment['originalSide'] ?? ''))
                        ?? $storedSide,
                    createdAt: $storedComment->created_at?->toIso8601String(),
                    updatedAt: $storedComment->updated_at?->toIso8601String(),
                    replies: CommentReplyData::collect($storedComment->replies->toArray()),
                );

                return (new CommentThreadSnapshot($comment))->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }

    private function dateOrNull(mixed $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : Carbon::parse($value)->toIso8601String();
    }
}
