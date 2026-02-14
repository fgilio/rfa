<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment;
use App\Services\CommentExporter;

final readonly class ExportReviewAction
{
    public function __construct(
        private BuildDiffContextAction $buildDiffContextAction,
        private CommentExporter $commentExporter,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, array<string, mixed>>  $files
     * @return array{json: string, md: string, clipboard: string}
     */
    public function handle(string $repoPath, array $comments, string $globalComment, array $files): array
    {
        $commentDTOs = array_map(fn ($c) => new Comment(
            id: $c['id'],
            file: $c['file'],
            side: $c['side'],
            startLine: $c['startLine'],
            endLine: $c['endLine'],
            body: $c['body'],
        ), $comments);

        $diffContext = $this->buildDiffContextAction->handle($repoPath, $comments, $files);

        return $this->commentExporter->export($repoPath, $commentDTOs, $globalComment, $diffContext);
    }
}
