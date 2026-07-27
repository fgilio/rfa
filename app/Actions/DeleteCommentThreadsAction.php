<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentThreadDeletion;
use App\DTOs\CommentThreadSnapshot;
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

        $commentsToDelete = collect($comments)
            ->whereIn('id', $commentIds)
            ->values()
            ->all();

        if ($commentsToDelete === []) {
            return null;
        }

        return DB::transaction(function () use (
            $repoPath,
            $projectId,
            $comments,
            $commentsToDelete,
            $surface,
        ): ?CommentThreadDeletion {
            $snapshots = $this->createSnapshots->handle(
                $repoPath,
                $projectId,
                $commentsToDelete,
                $surface,
            );

            if ($snapshots === []) {
                return null;
            }

            $storedCommentIds = collect($snapshots)->map(
                fn (array $snapshot): string => CommentThreadSnapshot::fromArray($snapshot)->commentId(),
            );

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
