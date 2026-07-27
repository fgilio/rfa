<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentReply as CommentReplyData;
use App\DTOs\CommentThreadSnapshot;
use App\Enums\CommentSurface;
use App\Models\Comment;
use Illuminate\Support\Carbon;

final readonly class CreateCommentThreadSnapshotsAction
{
    /**
     * Load database-authoritative roots and replies before a cascading delete.
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

                $comment = [
                    'id' => $storedComment->id,
                    'fileId' => (string) ($viewComment['fileId'] ?? ''),
                    'file' => $storedComment->file_path,
                    'side' => $storedComment->side,
                    'originalSide' => $viewComment['originalSide'] ?? $storedComment->side,
                    'startLine' => $storedComment->start_line,
                    'endLine' => $storedComment->end_line,
                    'body' => $storedComment->body,
                    'originRef' => $storedComment->origin_ref,
                    'fileContentHash' => $storedComment->file_content_hash,
                    'lineSnippet' => $storedComment->line_snippet,
                    'isDraft' => $storedComment->is_draft,
                    'submittedAt' => $this->dateOrNull($storedComment->getAttribute('submitted_at')),
                    'anchorStatus' => $viewComment['anchorStatus'] ?? 'placed',
                    'replies' => $storedComment->replies
                        ->map(fn ($reply): array => CommentReplyData::fromArray($reply->toArray())->toArray())
                        ->all(),
                    'createdAt' => $storedComment->created_at?->toIso8601String(),
                    'updatedAt' => $storedComment->updated_at?->toIso8601String(),
                ];

                return CommentThreadSnapshot::fromComment($comment)->toArray();
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
