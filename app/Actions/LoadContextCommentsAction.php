<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Comment;

/**
 * Load context-file comments for the Context page in the view-state shape
 * the diff-file Livewire component expects.
 *
 * Anchor resolution is delegated to ResolveContextCommentAnchorAction,
 * the working-tree sibling of ResolveCommentAnchorAction. See that
 * class for the placement rules.
 */
final readonly class LoadContextCommentsAction
{
    public function __construct(
        private ResolveContextCommentAnchorAction $resolveAnchor,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function handle(string $repoPath, ?int $projectId): array
    {
        $rows = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->fromContext()
            ->unsubmitted()
            ->with('replies')
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $row): array => $row->toArray());

        return $this->resolveAnchor->handle($repoPath, $rows);
    }
}
