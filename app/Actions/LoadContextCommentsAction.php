<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment as CommentDTO;
use App\Enums\DiffSide;
use App\Models\Comment;

/**
 * Load context-file comments for the Context page in the view-state shape
 * the diff-file Livewire component expects.
 *
 * Context files (CLAUDE.md, AGENTS.md and friends) are mutable working-tree
 * files, so a comment created against line 12 yesterday may now belong to a
 * different line, or to a vanished line entirely. Re-anchor each row before
 * handing it to the renderer:
 *
 * - File missing — anchorStatus becomes 'unplaced'.
 * - Stored content hash still matches — anchor stays 'placed', lines untouched.
 * - Hash drifted but line_snippet still occurs in the file — anchor 'placed'
 *   with start_line / end_line shifted to the new position (closest match
 *   to the original window when the snippet appears multiple times).
 * - Otherwise — 'unplaced', original lines kept as advisory hint.
 */
final readonly class LoadContextCommentsAction
{
    /** @return array<int, array<string, mixed>> */
    public function handle(string $repoPath, ?int $projectId): array
    {
        return Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->fromContext()
            ->unsubmitted()
            ->orderBy('created_at')
            ->get()
            ->map(fn (Comment $row): array => $this->reanchor($repoPath, $row))
            ->all();
    }

    /** @return array<string, mixed> */
    private function reanchor(string $repoPath, Comment $row): array
    {
        $side = DiffSide::from($row->side);
        $startLine = $row->start_line;
        $endLine = $row->end_line;
        $anchorStatus = 'placed';

        $content = $this->fileContent($repoPath.'/'.$row->file_path);

        if ($content === null) {
            $anchorStatus = 'unplaced';
        } elseif ($side !== DiffSide::File && $row->file_content_hash !== null && hash('xxh128', $content) !== $row->file_content_hash) {
            $shifted = $this->shiftedLines($content, $row->line_snippet, $row->start_line, $row->end_line);

            if ($shifted === null) {
                $anchorStatus = 'unplaced';
            } else {
                [$startLine, $endLine] = $shifted;
            }
        }

        return (new CommentDTO(
            id: $row->id,
            fileId: 'ctx-'.hash('xxh128', $row->file_path),
            file: $row->file_path,
            side: $side,
            startLine: $startLine,
            endLine: $endLine,
            body: $row->body,
            originRef: $row->origin_ref,
            fileContentHash: $row->file_content_hash,
            lineSnippet: $row->line_snippet,
            isDraft: $row->is_draft,
            // The query already filters submitted_at IS NULL, so this is
            // always null at the boundary, no point round-tripping it.
            submittedAt: null,
            anchorStatus: $anchorStatus,
        ))->toArray();
    }

    private function fileContent(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $content = @file_get_contents($absolutePath);

        return $content === false ? null : $content;
    }

    /**
     * Find $snippet in the current file. When it occurs more than once we
     * pick the occurrence whose first line is closest to the original
     * $startLine, keeping nearby drift (a few lines added above) anchored
     * over a coincidental match further away.
     *
     * @return array{0: int, 1: int}|null New [startLine, endLine], or null when unrecoverable.
     */
    private function shiftedLines(string $content, ?string $snippet, ?int $startLine, ?int $endLine): ?array
    {
        if ($snippet === null || $snippet === '' || $startLine === null) {
            return null;
        }

        $fileLines = explode("\n", $content);
        $snippetLines = explode("\n", $snippet);
        $snippetLen = count($snippetLines);
        $haystackLen = count($fileLines);

        if ($snippetLen > $haystackLen) {
            return null;
        }

        $needle = rtrim($snippet);
        $matches = [];
        for ($i = 0; $i <= $haystackLen - $snippetLen; $i++) {
            $candidate = array_slice($fileLines, $i, $snippetLen);
            if (rtrim(implode("\n", $candidate)) === $needle) {
                $matches[] = $i + 1;
            }
        }

        if ($matches === []) {
            return null;
        }

        $rangeLength = ($endLine ?? $startLine) - $startLine;
        $closest = collect($matches)
            ->sortBy(fn (int $n): int => abs($n - $startLine))
            ->first();

        return [$closest, $closest + $rangeLength];
    }
}
