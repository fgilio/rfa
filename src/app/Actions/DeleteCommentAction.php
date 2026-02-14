<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class DeleteCommentAction
{
    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>|null
     */
    public function handle(array $comments, string $commentId): ?array
    {
        if (! str_starts_with($commentId, 'c-')) {
            return null;
        }

        return array_values(
            array_filter($comments, fn ($c) => $c['id'] !== $commentId)
        );
    }
}
