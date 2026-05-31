<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use App\Services\GitFileContentService;
use App\Services\LineSnippetMatcherService;

final readonly class ResolveCommentAnchorAction
{
    public function __construct(
        private GitFileContentService $gitFileContentService,
        private LineSnippetMatcherService $snippetMatcher,
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
        $externalPathByPath = [];
        foreach ($currentFiles as $file) {
            $fileIdByPath[$file['path']] = $file['id'];
            $oldPathByPath[$file['path']] = $file['oldPath'] ?? null;
            if (! empty($file['isExternal']) && ! empty($file['externalAbsolutePath'])) {
                $externalPathByPath[$file['path']] = (string) $file['externalAbsolutePath'];
            }
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
            $storedSide = (string) ($row['side'] ?? DiffSide::Right->value);
            $storedOriginRef = (string) ($row['origin_ref'] ?? $row['originRef'] ?? GitRef::Working->value);
            $isExternal = $storedOriginRef === GitRef::External->value;
            $lineSnippet = $this->stringOrNull($row['line_snippet'] ?? $row['lineSnippet'] ?? null);
            $startLine = $this->intOrNull($row['start_line'] ?? $row['startLine'] ?? null);
            $endLine = $this->intOrNull($row['end_line'] ?? $row['endLine'] ?? null);

            $anchorStatus = AnchorStatus::Unplaced;
            $resolvedSide = $storedSide;

            if ($isExternal) {
                $absolute = $externalPathByPath[$filePath] ?? null;

                if ($absolute !== null) {
                    $hasHash = $storedHash !== null && $storedHash !== '';

                    if (! $hasHash || $storedHash === $this->gitFileContentService->hashAtAbsolute($absolute)) {
                        $anchorStatus = AnchorStatus::Placed;
                    } elseif ($lineSnippet !== null && $startLine !== null) {
                        // Hash drifted but the snippet may still occur — re-anchor
                        // instead of dropping. contentAtAbsolute returns null when the
                        // file is gone, which the matcher rejects.
                        $shifted = $this->snippetMatcher->shiftedLines(
                            (string) $this->gitFileContentService->contentAtAbsolute($absolute),
                            $lineSnippet,
                            $startLine,
                        );

                        if ($shifted !== null) {
                            $anchorStatus = AnchorStatus::Placed;
                            [$startLine, $endLine] = $shifted;
                        }
                    }
                }
            } elseif ($storedHash !== null && $storedHash !== '' && isset($fileIdByPath[$filePath])) {
                // Renamed files: left-side content lives at `oldPath`; right-side stays
                // at `path`. Without this, left comments on rename+edit diffs would be
                // stamped unplaced and then silently dropped from submit.
                $leftPath = $oldPathByPath[$filePath] ?? $filePath;
                $leftHash = $this->gitFileContentService->hashAt($repoPath, $target->from(), $leftPath);
                $rightHash = $this->gitFileContentService->hashAt($repoPath, $rightRef, $filePath);

                $matchesStoredSide = ($storedSide === DiffSide::Left->value && $storedHash === $leftHash)
                    || ($storedSide === DiffSide::Right->value && $storedHash === $rightHash)
                    || ($storedSide === DiffSide::File->value && ($storedHash === $leftHash || $storedHash === $rightHash));

                if ($matchesStoredSide) {
                    $anchorStatus = AnchorStatus::Placed;
                } elseif ($storedHash === $leftHash) {
                    $anchorStatus = AnchorStatus::Placed;
                    $resolvedSide = DiffSide::Left->value;
                } elseif ($storedHash === $rightHash) {
                    $anchorStatus = AnchorStatus::Placed;
                    $resolvedSide = DiffSide::Right->value;
                } else {
                    // Whole-file hash drifted on both sides — typically an unrelated
                    // edit elsewhere in the file. Locate the stored line snippet in the
                    // current content so the comment survives and re-anchors, instead of
                    // being stamped unplaced and silently dropped at submit.
                    $recovered = $this->recoverBySnippet($repoPath, $target, $storedSide, $filePath, $leftPath, $rightRef, $lineSnippet, $startLine);

                    if ($recovered !== null) {
                        [$resolvedSide, $startLine, $endLine] = $recovered;
                        $anchorStatus = AnchorStatus::Placed;
                    }
                }
            } elseif (isset($fileIdByPath[$filePath])) {
                $anchorStatus = AnchorStatus::Placed;
            }

            $resolved[] = [
                'id' => (string) $row['id'],
                'fileId' => $fileId,
                'file' => $filePath,
                'side' => $resolvedSide,
                'originalSide' => $storedSide,
                'startLine' => $startLine,
                'endLine' => $endLine,
                'body' => (string) ($row['body'] ?? ''),
                'originRef' => $storedOriginRef,
                'fileContentHash' => $storedHash,
                'lineSnippet' => $lineSnippet,
                'isDraft' => (bool) ($row['is_draft'] ?? $row['isDraft'] ?? false),
                'submittedAt' => $row['submitted_at'] ?? $row['submittedAt'] ?? null,
                'anchorStatus' => $anchorStatus->value,
            ];
        }

        return $resolved;
    }

    /**
     * Re-anchor a drifted comment by finding its line snippet in the current
     * content. Searches the stored side first, then the opposite side (flipping
     * it on a match) so a comment whose side changed still recovers. Returns
     * [side, startLine, endLine] or null when the snippet can't be found.
     *
     * @return array{0: string, 1: int, 2: int}|null
     */
    private function recoverBySnippet(string $repoPath, DiffTarget $target, string $storedSide, string $filePath, string $leftPath, string $rightRef, ?string $snippet, ?int $startLine): ?array
    {
        // No snippet means nothing to search for — skip the content fetch entirely
        // (avoids a `git show` per drifted comment that could never re-anchor).
        if ($snippet === null || $snippet === '' || $startLine === null) {
            return null;
        }

        $left = fn (): ?string => $this->gitFileContentService->contentAt($repoPath, $target->from(), $leftPath);
        $right = fn (): ?string => $this->gitFileContentService->contentAt($repoPath, $rightRef, $filePath);

        $order = $storedSide === DiffSide::Left->value
            ? [[DiffSide::Left->value, $left], [DiffSide::Right->value, $right]]
            : [[DiffSide::Right->value, $right], [DiffSide::Left->value, $left]];

        foreach ($order as [$side, $fetch]) {
            $content = $fetch();
            if ($content === null) {
                continue;
            }

            $shifted = $this->snippetMatcher->shiftedLines($content, $snippet, $startLine);
            if ($shifted !== null) {
                return [$side, $shifted[0], $shifted[1]];
            }
        }

        return null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
