<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentDTO;
use App\DTOs\CommentReply;
use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Services\LineSnippetMatcherService;
use Illuminate\Support\Facades\File;

/**
 * Resolve placement for context-page comments against the current
 * working-tree state of the file they were left on.
 *
 * Sibling to ResolveCommentAnchorAction, which solves the same problem
 * for review-page comments (across diff refs, with side flipping).
 * Context comments only ever live on the working copy, so the rules
 * are simpler and the recovery strategy is different:
 *
 * - File missing from disk      anchorStatus is Unplaced.
 * - Stored hash matches content anchorStatus is Placed, lines kept.
 * - Hash drifted but line_snippet still occurs in the file
 *                               anchorStatus is Placed with start/end
 *                               line shifted to the new position. When
 *                               the snippet matches multiple times we
 *                               pick the occurrence closest to the
 *                               original line so nearby drift wins
 *                               over a coincidental match further
 *                               away.
 * - Otherwise                   anchorStatus is Unplaced, original
 *                               lines kept as an advisory hint.
 *
 * File-level comments (side === DiffSide::File) carry no line bounds,
 * so they stay Placed as long as the file itself exists.
 */
final readonly class ResolveContextCommentAnchorAction
{
    public function __construct(
        private LineSnippetMatcherService $snippetMatcher,
    ) {}

    /**
     * @param  iterable<array<string, mixed>>  $rawComments  Rows from the comments table or their array form.
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath, iterable $rawComments): array
    {
        $contentCache = [];
        $resolved = [];

        foreach ($rawComments as $row) {
            $resolved[] = $this->resolveOne($repoPath, $row, $contentCache);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, ?string>  $contentCache
     * @return array<string, mixed>
     */
    private function resolveOne(string $repoPath, array $row, array &$contentCache): array
    {
        $filePath = (string) ($row['file_path'] ?? $row['file'] ?? '');
        $side = DiffSide::from((string) ($row['side'] ?? DiffSide::Right->value));
        $startLine = $this->intOrNull($row['start_line'] ?? $row['startLine'] ?? null);
        $endLine = $this->intOrNull($row['end_line'] ?? $row['endLine'] ?? null);
        $storedHash = $row['file_content_hash'] ?? $row['fileContentHash'] ?? null;
        $lineSnippet = $row['line_snippet'] ?? $row['lineSnippet'] ?? null;

        $absolute = $repoPath.'/'.$filePath;
        if (! array_key_exists($absolute, $contentCache)) {
            $contentCache[$absolute] = $this->readFile($absolute);
        }
        $content = $contentCache[$absolute];

        $anchorStatus = AnchorStatus::Placed;

        if ($content === null) {
            $anchorStatus = AnchorStatus::Unplaced;
        } elseif ($side !== DiffSide::File && $storedHash !== null && hash('xxh128', $content) !== $storedHash) {
            $shifted = $this->snippetMatcher->shiftedLines($content, $lineSnippet, $startLine);

            if ($shifted === null) {
                $anchorStatus = AnchorStatus::Unplaced;
            } else {
                [$startLine, $endLine] = $shifted;
            }
        }

        return (new CommentDTO(
            id: (string) ($row['id'] ?? ''),
            fileId: 'ctx-'.hash('xxh128', $filePath),
            file: $filePath,
            side: $side,
            startLine: $startLine,
            endLine: $endLine,
            body: (string) ($row['body'] ?? ''),
            originRef: (string) ($row['origin_ref'] ?? $row['originRef'] ?? ''),
            fileContentHash: $storedHash,
            lineSnippet: $lineSnippet,
            isDraft: (bool) ($row['is_draft'] ?? $row['isDraft'] ?? false),
            submittedAt: $row['submitted_at'] ?? $row['submittedAt'] ?? null,
            anchorStatus: $anchorStatus->value,
            replies: CommentReply::collect($row['replies'] ?? []),
        ))->toArray();
    }

    private function readFile(string $absolutePath): ?string
    {
        return rescue(
            fn (): ?string => File::isFile($absolutePath) ? File::get($absolutePath) : null,
            null,
            report: false,
        );
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
