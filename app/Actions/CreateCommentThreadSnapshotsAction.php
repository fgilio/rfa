<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentData;
use App\DTOs\CommentThreadSnapshot;
use App\Enums\CommentSurface;
use App\Models\Comment;

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

                $comment = CommentData::fromArray([
                    ...$storedComment->toArray(),
                    'fileId' => $viewComment['fileId'] ?? null,
                    'anchorStatus' => $viewComment['anchorStatus'] ?? null,
                    'originalSide' => $viewComment['originalSide'] ?? null,
                ]);

                return (new CommentThreadSnapshot($comment))->toArray();
            })
            ->filter()
            ->values()
            ->all();
    }
}
