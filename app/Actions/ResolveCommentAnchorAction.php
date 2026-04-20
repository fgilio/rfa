<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Enums\GitRef;
use App\Services\GitFileContentService;

final readonly class ResolveCommentAnchorAction
{
    public function __construct(
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * Resolve placement for every comment against the current diff target.
     *
     * - Anchors by `file_content_hash` + line number.
     * - Matches against the file hash on either the left (`target.from`) or right
     *   (`target.to`, or working copy when null) side of the current diff.
     * - Returns an array of view-ready comment rows with `fileId`, `anchorStatus`
     *   (`'placed'` or `'unplaced'`).
     *
     * @param  iterable<array<string, mixed>>  $rawComments  Rows from the comments table or their array form.
     * @param  array<int, array<string, mixed>>  $currentFiles  Output of GetFileListAction (includes `id`, `path`).
     * @return array<int, array<string, mixed>>
     */
    public function handle(string $repoPath, iterable $rawComments, array $currentFiles, DiffTarget $target): array
    {
        $fileIdByPath = [];
        $oldPathByPath = [];
        foreach ($currentFiles as $file) {
            $fileIdByPath[$file['path']] = $file['id'];
            $oldPathByPath[$file['path']] = $file['oldPath'] ?? null;
        }

        $resolved = [];
        $rightRef = $target->to() ?? GitRef::Working->value;

        foreach ($rawComments as $row) {
            $filePath = (string) ($row['file_path'] ?? $row['file'] ?? '');
            if ($filePath === '') {
                continue;
            }

            $storedHash = $row['file_content_hash'] ?? null;
            $fileId = $fileIdByPath[$filePath] ?? FileListEntry::idForPath($filePath);
            $storedSide = (string) ($row['side'] ?? 'right');

            $anchorStatus = 'unplaced';
            $resolvedSide = $storedSide;

            if ($storedHash !== null && $storedHash !== '' && isset($fileIdByPath[$filePath])) {
                // Renamed files: left-side content lives at `oldPath`; right-side stays
                // at `path`. Without this, left comments on rename+edit diffs would be
                // stamped unplaced and then silently dropped from submit.
                $leftPath = $oldPathByPath[$filePath] ?? $filePath;
                $leftHash = $this->gitFileContentService->hashAt($repoPath, $target->from(), $leftPath);
                $rightHash = $this->gitFileContentService->hashAt($repoPath, $rightRef, $filePath);

                $matchesStoredSide = ($storedSide === 'left' && $storedHash === $leftHash)
                    || ($storedSide === 'right' && $storedHash === $rightHash)
                    || ($storedSide === 'file' && ($storedHash === $leftHash || $storedHash === $rightHash));

                if ($matchesStoredSide) {
                    $anchorStatus = 'placed';
                } elseif ($storedHash === $leftHash) {
                    $anchorStatus = 'placed';
                    $resolvedSide = 'left';
                } elseif ($storedHash === $rightHash) {
                    $anchorStatus = 'placed';
                    $resolvedSide = 'right';
                }
            } elseif (isset($fileIdByPath[$filePath])) {
                $anchorStatus = 'placed';
            }

            $resolved[] = [
                'id' => (string) $row['id'],
                'fileId' => $fileId,
                'file' => $filePath,
                'side' => $resolvedSide,
                'originalSide' => $storedSide,
                'startLine' => $this->intOrNull($row['start_line'] ?? $row['startLine'] ?? null),
                'endLine' => $this->intOrNull($row['end_line'] ?? $row['endLine'] ?? null),
                'body' => (string) ($row['body'] ?? ''),
                'originRef' => (string) ($row['origin_ref'] ?? $row['originRef'] ?? GitRef::Working->value),
                'fileContentHash' => $storedHash,
                'lineSnippet' => $row['line_snippet'] ?? $row['lineSnippet'] ?? null,
                'isDraft' => (bool) ($row['is_draft'] ?? $row['isDraft'] ?? false),
                'submittedAt' => $row['submitted_at'] ?? $row['submittedAt'] ?? null,
                'anchorStatus' => $anchorStatus,
            ];
        }

        return $resolved;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
