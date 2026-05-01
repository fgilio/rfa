<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DiffSide;
use App\Models\Comment;
use App\Services\GitFileContentService;
use Illuminate\Support\Str;

/**
 * Comment workflow for the Context page (CLAUDE.md / AGENTS.md inventory).
 *
 * Lives parallel to review-page's inline comment writes. Uses the deterministic
 * sentinel `origin_ref = ContextCommentWorkflowAction::ORIGIN_REF` so context-
 * file comments coexist with review comments on the same `(repo_path, file_path)`
 * row without collision. Designed as a sibling to the pending
 * `ReviewCommentWorkflow` extraction noted in this project's CLAUDE.md.
 *
 * `handle()` adds a new comment (the primary entry point); `update()` and
 * `delete()` are the secondary operations.
 */
final readonly class ContextCommentWorkflowAction
{
    public const ORIGIN_REF = 'context-file';

    public function __construct(
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * Add a comment (draft or submitted) on a context file.
     *
     * @param  array<int, array<string, mixed>>  $contextFiles  AgentContextFile::toArray() entries.
     * @return array<string, mixed>|null
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        array $contextFiles,
        string $fileId,
        string $side,
        ?int $startLine,
        ?int $endLine,
        string $body,
        bool $isDraft = false,
        ?string $lineSnippet = null,
    ): ?array {
        if (trim($body) === '') {
            return null;
        }

        $file = collect($contextFiles)->firstWhere('id', $fileId);
        if (! $file) {
            return null;
        }

        if (! $this->validateSideAndLines($side, $startLine, $endLine)) {
            return null;
        }

        $lineCount = isset($file['lineCount']) ? (int) $file['lineCount'] : null;
        if (! $this->validateLineBounds($side, $startLine, $endLine, $lineCount)) {
            return null;
        }

        $filePath = (string) $file['path'];
        $absolutePath = (string) ($file['absolutePath'] ?? ($repoPath.'/'.$filePath));
        $contentHash = $this->gitFileContentService->hashAtAbsolute($absolutePath);

        $id = 'c-'.Str::ulid();

        Comment::create([
            'id' => $id,
            'project_id' => $projectId,
            'repo_path' => $repoPath,
            'origin_ref' => self::ORIGIN_REF,
            'file_path' => $filePath,
            'side' => $side,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'file_content_hash' => $contentHash,
            'line_snippet' => $lineSnippet,
            'body' => $body,
            'is_draft' => $isDraft,
        ]);

        return [
            'id' => $id,
            'fileId' => $fileId,
            'file' => $filePath,
            'side' => $side,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'body' => $body,
            'originRef' => self::ORIGIN_REF,
            'fileContentHash' => $contentHash,
            'lineSnippet' => $lineSnippet,
            'isDraft' => $isDraft,
            'submittedAt' => null,
            'anchorStatus' => 'placed',
        ];
    }

    public function update(string $commentId, string $body, bool $isDraft = false): bool
    {
        if (trim($body) === '') {
            return false;
        }

        if (! str_starts_with($commentId, 'c-')) {
            return false;
        }

        return Comment::whereKey($commentId)
            ->where('origin_ref', self::ORIGIN_REF)
            ->update(['body' => $body, 'is_draft' => $isDraft]) > 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @return array<int, array<string, mixed>>|null Updated comments view, or null if invalid id.
     */
    public function delete(array $comments, string $commentId): ?array
    {
        if (! str_starts_with($commentId, 'c-')) {
            return null;
        }

        Comment::whereKey($commentId)
            ->where('origin_ref', self::ORIGIN_REF)
            ->delete();

        return collect($comments)
            ->reject(fn (array $comment): bool => $comment['id'] === $commentId)
            ->values()
            ->all();
    }

    private function validateSideAndLines(string $side, ?int $startLine, ?int $endLine): bool
    {
        $sideEnum = DiffSide::tryFrom($side);
        if ($sideEnum === null) {
            return false;
        }

        // Context files only render the additions (right) side; the left
        // side is always /dev/null and cannot be commented on.
        if ($sideEnum === DiffSide::Left) {
            return false;
        }

        if ($sideEnum === DiffSide::File && ($startLine !== null || $endLine !== null)) {
            return false;
        }

        if ($sideEnum !== DiffSide::File && $startLine === null) {
            return false;
        }

        if ($startLine !== null && $endLine !== null && $startLine > $endLine) {
            return false;
        }

        return true;
    }

    /**
     * Reject anchors that fall outside the file. Defends against stale Livewire
     * payloads (e.g. user clicked line 90 in a now-50-line file). File-level
     * comments have no line numbers and aren't bounded; line-level comments
     * are. When the scanner couldn't read the file (lineCount === null), skip
     * the check rather than rejecting valid input.
     */
    private function validateLineBounds(string $side, ?int $startLine, ?int $endLine, ?int $lineCount): bool
    {
        if ($side === DiffSide::File->value || $startLine === null || $lineCount === null) {
            return true;
        }

        $lastLine = $endLine ?? $startLine;

        return $startLine >= 1 && $lastLine <= $lineCount;
    }
}
