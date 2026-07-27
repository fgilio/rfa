<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentData;
use App\Models\Comment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class LoadCommentsDrawerAction
{
    /**
     * @return array{
     *     groupedComments: array<string, list<array<string, mixed>>>,
     *     totalCount: int
     * }
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        bool $showSubmitted = false,
        string $filter = '',
        bool $includeRows = true,
    ): array {
        $query = Comment::query()->forProjectOrRepo($projectId, $repoPath);

        if (! $showSubmitted) {
            $query->unsubmitted();
        }

        $totalCount = (clone $query)->count();

        if (! $includeRows) {
            return [
                'groupedComments' => [],
                'totalCount' => $totalCount,
            ];
        }

        $filter = trim($filter);

        if ($filter !== '') {
            $query->where(function (Builder $commentQuery) use ($filter): void {
                $commentQuery
                    ->where('file_path', 'like', '%'.$filter.'%')
                    ->orWhere('body', 'like', '%'.$filter.'%')
                    ->orWhereHas('replies', function (Builder $replyQuery) use ($filter): void {
                        $replyQuery
                            ->where('body', 'like', '%'.$filter.'%')
                            ->orWhere('author_key', 'like', '%'.$filter.'%')
                            ->orWhere('author_label', 'like', '%'.$filter.'%');
                    });
            });
        }

        $groupedComments = $query
            ->with('replies')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (Comment $comment) use ($filter): array {
                $data = CommentData::fromArray($comment->toArray())->toArray();

                return [
                    ...$data,
                    'createdAt' => $comment->created_at?->toIso8601String(),
                    'updatedAt' => $comment->updated_at?->toIso8601String(),
                    'isReplyFilterMatch' => $this->repliesMatchFilter($data['replies'], $filter),
                ];
            })
            ->groupBy('file')
            ->map(fn ($comments): array => $comments->values()->all())
            ->all();

        return [
            'groupedComments' => $groupedComments,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $replies
     */
    private function repliesMatchFilter(array $replies, string $filter): bool
    {
        if ($filter === '') {
            return false;
        }

        return collect($replies)->contains(
            fn (array $reply): bool => collect([
                $reply['body'] ?? '',
                $reply['authorKey'] ?? '',
                $reply['authorLabel'] ?? '',
            ])->contains(
                fn (mixed $value): bool => is_string($value)
                    && Str::contains($value, $filter, ignoreCase: true),
            ),
        );
    }
}
