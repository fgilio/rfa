<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment;
use App\DTOs\DiffTarget;
use App\Enums\AnchorStatus;
use App\Models\Comment as CommentModel;
use App\Services\CommentExporter;

final readonly class ExportReviewAction
{
    public function __construct(
        private BuildDiffContextAction $buildDiffContextAction,
        private CommentExporter $commentExporter,
        private EnsureRfaGitExcludeAction $ensureGitExclude,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments  Currently-loaded, view-ready comments.
     * @param  array<int, array<string, mixed>>  $files  Files in the current diff.
     * @return array{md: string, clipboard: string, submittedIds: array<int, string>, excludedComments: array<int, array<string, mixed>>}
     */
    public function handle(string $repoPath, array $comments, string $globalComment, array $files, ?DiffTarget $target = null): array
    {
        [$inScope, $excluded] = $this->partitionByScope($comments, $target);

        $commentDTOs = array_map(fn ($c) => Comment::fromArray($c), $inScope);

        $diffContext = $this->buildDiffContextAction->handle($repoPath, $inScope, $files, $target);

        $result = $this->commentExporter->export($repoPath, $commentDTOs, $globalComment, $diffContext);

        $ids = array_column($inScope, 'id');
        if ($ids !== []) {
            CommentModel::whereIn('id', $ids)->update(['submitted_at' => now()]);
        }

        $this->ensureGitExclude->handle($repoPath);

        return [...$result, 'submittedIds' => $ids, 'excludedComments' => $excluded];
    }

    /**
     * Split comments into the ones the anchor resolver placed against the active
     * diff (exported + stamped submitted) and the ones it couldn't (left
     * untouched: no submitted_at stamp, not exported). Surfacing the excluded
     * set lets the caller warn the user instead of silently dropping them.
     *
     * Comments without an explicit `anchorStatus` (e.g. fresh inputs in direct
     * callers / tests) default to placed.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function partitionByScope(array $comments, ?DiffTarget $target): array
    {
        if ($target === null) {
            return [$comments, []];
        }

        $inScope = [];
        $excluded = [];

        foreach ($comments as $comment) {
            $placed = ($comment['anchorStatus'] ?? AnchorStatus::Placed->value) === AnchorStatus::Placed->value;
            if ($placed) {
                $inScope[] = $comment;
            } else {
                $excluded[] = $comment;
            }
        }

        return [$inScope, $excluded];
    }
}
