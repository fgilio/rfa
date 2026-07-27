<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentData;
use App\DTOs\CommentReply as CommentReplyData;
use App\DTOs\CommentThreadSnapshot;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use App\Models\Comment;
use App\Models\CommentReply;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class RestoreCommentThreadsAction
{
    /**
     * @param  list<array<string, mixed>>  $snapshots
     * @return list<array<string, mixed>>
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        array $snapshots,
        string $defaultOriginRef = GitRef::Working->value,
    ): array {
        $threads = collect($snapshots)
            ->map(fn (array $snapshot): CommentThreadSnapshot => CommentThreadSnapshot::fromArray($snapshot))
            ->values();

        if ($threads->isEmpty()) {
            return [];
        }

        $this->validateSnapshots($threads);

        return DB::transaction(function () use ($repoPath, $projectId, $threads, $defaultOriginRef): array {
            $restoredAt = now();
            $commentIds = $threads->map(
                fn (CommentThreadSnapshot $thread): string => $thread->commentId(),
            );
            $replies = $threads->flatMap(
                fn (CommentThreadSnapshot $thread): array => $thread->replies,
            )->values();

            $this->ensureExistingCommentsBelongToScope($commentIds, $repoPath, $projectId);
            $this->ensureExistingRepliesBelongToThreads($replies);

            Comment::query()->upsert(
                $threads->map(
                    fn (CommentThreadSnapshot $thread): array => $this->rootAttributes(
                        $repoPath,
                        $projectId,
                        $thread,
                        $defaultOriginRef,
                        $restoredAt,
                    ),
                )->all(),
                ['id'],
                [
                    'project_id',
                    'repo_path',
                    'origin_ref',
                    'file_path',
                    'side',
                    'start_line',
                    'end_line',
                    'file_content_hash',
                    'line_snippet',
                    'body',
                    'is_draft',
                    'submitted_at',
                    'created_at',
                    'updated_at',
                ],
            );

            if ($replies->isNotEmpty()) {
                CommentReply::query()->upsert(
                    $replies
                        ->map(fn (CommentReplyData $reply): array => $this->replyAttributes($reply, $restoredAt))
                        ->all(),
                    ['id'],
                    [
                        'comment_id',
                        'author_type',
                        'author_key',
                        'author_label',
                        'body',
                        'created_at',
                        'updated_at',
                    ],
                );
            }

            $restored = Comment::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->whereKey($commentIds)
                ->with('replies')
                ->get()
                ->keyBy('id');

            return $threads
                ->map(function (CommentThreadSnapshot $thread) use ($restored): array {
                    $comment = $restored->get($thread->commentId());

                    if (! $comment instanceof Comment) {
                        throw (new ModelNotFoundException)->setModel(Comment::class, [$thread->commentId()]);
                    }

                    $loaded = CommentData::fromArray($comment->toArray())->toArray();

                    return [
                        ...$thread->toCommentArray(),
                        'replies' => $loaded['replies'],
                        'createdAt' => $comment->created_at?->toIso8601String(),
                        'updatedAt' => $comment->updated_at?->toIso8601String(),
                    ];
                })
                ->all();
        });
    }

    /**
     * @param  Collection<int, CommentThreadSnapshot>  $threads
     */
    private function validateSnapshots(Collection $threads): void
    {
        $commentIds = $threads->map(
            fn (CommentThreadSnapshot $thread): string => $thread->commentId(),
        );

        if ($commentIds->contains('')) {
            throw new InvalidArgumentException('Every comment thread snapshot must contain a comment ID.');
        }

        if ($commentIds->duplicatesStrict()->isNotEmpty()) {
            throw new InvalidArgumentException('Comment thread snapshot IDs must be unique.');
        }

        $replyIds = $threads
            ->flatMap(function (CommentThreadSnapshot $thread): array {
                collect($thread->replies)->each(function (CommentReplyData $reply) use ($thread): void {
                    if ($reply->commentId !== $thread->commentId()) {
                        throw new InvalidArgumentException(
                            "Reply {$reply->id} does not belong to comment {$thread->commentId()}.",
                        );
                    }
                });

                return $thread->replies;
            })
            ->map(fn (CommentReplyData $reply): string => $reply->id);

        if ($replyIds->duplicatesStrict()->isNotEmpty()) {
            throw new InvalidArgumentException('Comment reply snapshot IDs must be unique.');
        }
    }

    /**
     * @param  Collection<int, string>  $commentIds
     */
    private function ensureExistingCommentsBelongToScope(
        Collection $commentIds,
        string $repoPath,
        ?int $projectId,
    ): void {
        Comment::query()
            ->whereKey($commentIds)
            ->lockForUpdate()
            ->get()
            ->each(function (Comment $comment) use ($repoPath, $projectId): void {
                if (! $this->belongsToScope($comment, $repoPath, $projectId)) {
                    throw (new ModelNotFoundException)->setModel(Comment::class, [$comment->getKey()]);
                }
            });
    }

    /**
     * @param  Collection<int, CommentReplyData>  $replies
     */
    private function ensureExistingRepliesBelongToThreads(Collection $replies): void
    {
        if ($replies->isEmpty()) {
            return;
        }

        $expectedCommentIds = $replies->mapWithKeys(
            fn (CommentReplyData $reply): array => [$reply->id => $reply->commentId],
        );

        CommentReply::query()
            ->whereKey($expectedCommentIds->keys())
            ->lockForUpdate()
            ->get()
            ->each(function (CommentReply $reply) use ($expectedCommentIds): void {
                if ($reply->comment_id !== $expectedCommentIds->get($reply->id)) {
                    throw (new ModelNotFoundException)->setModel(CommentReply::class, [$reply->getKey()]);
                }
            });
    }

    /** @return array<string, mixed> */
    private function rootAttributes(
        string $repoPath,
        ?int $projectId,
        CommentThreadSnapshot $thread,
        string $defaultOriginRef,
        Carbon $restoredAt,
    ): array {
        $comment = $thread->comment;

        return [
            'id' => $thread->commentId(),
            'project_id' => $projectId,
            'repo_path' => $repoPath,
            'origin_ref' => (string) ($comment['originRef'] ?? $defaultOriginRef),
            'file_path' => (string) ($comment['file'] ?? ''),
            'side' => (string) ($comment['side'] ?? DiffSide::Right->value),
            'start_line' => $comment['startLine'] ?? null,
            'end_line' => $comment['endLine'] ?? null,
            'file_content_hash' => $comment['fileContentHash'] ?? null,
            'line_snippet' => $comment['lineSnippet'] ?? null,
            'body' => (string) ($comment['body'] ?? ''),
            'is_draft' => (bool) ($comment['isDraft'] ?? false),
            'submitted_at' => $this->dateOrNull($comment['submittedAt'] ?? null),
            'created_at' => $this->dateOr($comment['createdAt'] ?? null, $restoredAt),
            'updated_at' => $this->dateOr($comment['updatedAt'] ?? null, $restoredAt),
        ];
    }

    /** @return array<string, mixed> */
    private function replyAttributes(CommentReplyData $reply, Carbon $restoredAt): array
    {
        return [
            ...$reply->toDatabaseArray(),
            'created_at' => $this->dateOr($reply->createdAt, $restoredAt)
                ->format((new CommentReply)->getDateFormat()),
            'updated_at' => $this->dateOr($reply->updatedAt, $restoredAt)
                ->format((new CommentReply)->getDateFormat()),
        ];
    }

    private function belongsToScope(Comment $comment, string $repoPath, ?int $projectId): bool
    {
        if ($projectId !== null) {
            return $comment->project_id === $projectId;
        }

        return $comment->project_id === null && $comment->repo_path === $repoPath;
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        return $value === null || $value === '' ? null : Carbon::parse((string) $value);
    }

    private function dateOr(mixed $value, Carbon $fallback): Carbon
    {
        return $value === null || $value === '' ? $fallback : Carbon::parse((string) $value);
    }
}
