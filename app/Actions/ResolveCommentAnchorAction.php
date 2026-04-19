<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
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
        foreach ($currentFiles as $file) {
            $fileIdByPath[$file['path']] = $file['id'];
        }

        $resolved = [];
        $rightRef = $target->to() ?? GitFileContentService::WORKING_REF;

        foreach ($rawComments as $row) {
            $filePath = (string) ($row['file_path'] ?? $row['file'] ?? '');
            if ($filePath === '') {
                continue;
            }

            $storedHash = $row['file_content_hash'] ?? null;
            $fileId = $fileIdByPath[$filePath] ?? FileListEntry::idForPath($filePath);

            $anchorStatus = 'unplaced';

            if ($storedHash !== null && $storedHash !== '' && isset($fileIdByPath[$filePath])) {
                $leftHash = $this->gitFileContentService->hashAt($repoPath, $target->from(), $filePath);
                $rightHash = $this->gitFileContentService->hashAt($repoPath, $rightRef, $filePath);

                if ($storedHash === $leftHash || $storedHash === $rightHash) {
                    $anchorStatus = 'placed';
                }
            } elseif (isset($fileIdByPath[$filePath])) {
                $anchorStatus = 'placed';
            }

            $resolved[] = [
                'id' => (string) $row['id'],
                'fileId' => $fileId,
                'file' => $filePath,
                'side' => (string) ($row['side'] ?? 'right'),
                'startLine' => $this->intOrNull($row['start_line'] ?? $row['startLine'] ?? null),
                'endLine' => $this->intOrNull($row['end_line'] ?? $row['endLine'] ?? null),
                'body' => (string) ($row['body'] ?? ''),
                'originRef' => (string) ($row['origin_ref'] ?? $row['originRef'] ?? GitFileContentService::WORKING_REF),
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
