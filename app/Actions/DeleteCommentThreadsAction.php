<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentThreadDeletion;
use App\Enums\CommentSurface;
use App\Models\Comment;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCommentThreadsAction
{
    public function __construct(
        private CreateCommentThreadSnapshotsAction $createSnapshots,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $comments
     * @param  list<string>  $commentIds
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        array $comments,
        array $commentIds,
        CommentSurface $surface,
    ): ?CommentThreadDeletion {
        $commentIds = collect($commentIds)
            ->filter(fn (string $commentId): bool => str_starts_with($commentId, 'c-'))
            ->unique()
            ->values();

        if ($commentIds->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use (
            $repoPath,
            $projectId,
            $comments,
            $commentIds,
            $surface,
        ): ?CommentThreadDeletion {
            $viewComments = collect($comments)->keyBy('id');
            $commentsToDelete = Comment::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->fromSurface($surface)
                ->whereKey($commentIds)
                ->get(['id'])
                ->map(function (Comment $comment) use ($viewComments): array {
                    $viewComment = $viewComments->get($comment->id);

                    return is_array($viewComment)
                        ? $viewComment
                        : ['id' => $comment->id];
                })
                ->all();

            if ($commentsToDelete === []) {
                return null;
            }

            $snapshots = $this->createSnapshots->handle(
                $repoPath,
                $projectId,
                $commentsToDelete,
                $surface,
            );

            if ($snapshots === []) {
                return null;
            }

            $storedCommentIds = collect($snapshots)->pluck('comment.id');

            Comment::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->fromSurface($surface)
                ->whereKey($storedCommentIds)
                ->delete();

            return new CommentThreadDeletion(
                remainingComments: collect($comments)
                    ->reject(fn (array $comment): bool => $storedCommentIds->contains($comment['id'] ?? null))
                    ->values()
                    ->all(),
                snapshots: $snapshots,
            );
        });
    }
}
