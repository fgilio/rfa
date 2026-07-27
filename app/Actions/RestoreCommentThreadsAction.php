<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentThreadSnapshot;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use App\Models\Comment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class RestoreCommentThreadsAction
{
    public function __construct(
        private readonly RestoreCommentReplyAction $restoreCommentReply,
        private readonly LoadCommentThreadAction $loadCommentThread,
    ) {}

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

        return DB::transaction(function () use ($repoPath, $projectId, $threads, $defaultOriginRef): array {
            $threads->each(function (CommentThreadSnapshot $thread) use ($repoPath, $projectId, $defaultOriginRef): void {
                $this->restoreRoot($repoPath, $projectId, $thread, $defaultOriginRef);

                collect($thread->replies)->each(
                    fn ($reply) => $this->restoreCommentReply->handle($repoPath, $projectId, $reply),
                );
            });

            return $threads
                ->map(function (CommentThreadSnapshot $thread) use ($repoPath, $projectId): ?array {
                    $loaded = $this->loadCommentThread->handle($repoPath, $projectId, $thread->commentId());

                    if ($loaded === null) {
                        return null;
                    }

                    return [
                        ...$thread->toCommentArray(),
                        'replies' => $loaded['replies'],
                        'createdAt' => $loaded['createdAt'],
                        'updatedAt' => $loaded['updatedAt'],
                    ];
                })
                ->filter()
                ->values()
                ->all();
        });
    }

    private function restoreRoot(
        string $repoPath,
        ?int $projectId,
        CommentThreadSnapshot $thread,
        string $defaultOriginRef,
    ): void {
        $comment = $thread->comment;
        $commentId = $thread->commentId();
        $model = Comment::query()->find($commentId) ?? new Comment;

        if ($model->exists && ! $this->belongsToScope($model, $repoPath, $projectId)) {
            throw (new ModelNotFoundException)->setModel(Comment::class, [$commentId]);
        }

        $model->forceFill([
            'id' => $commentId,
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
            'created_at' => $this->dateOrNow($comment['createdAt'] ?? null),
            'updated_at' => $this->dateOrNow($comment['updatedAt'] ?? null),
        ]);
        $model->timestamps = false;
        $model->save();
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

    private function dateOrNow(mixed $value): Carbon
    {
        return $value === null || $value === '' ? now() : Carbon::parse((string) $value);
    }
}
