<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\Comment;
use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\DTOs\FileSourceSpec;
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
     * - Returns view-ready {@see Comment} arrays with `fileId`, `anchorStatus`
     *   (`'placed'` or `'unplaced'`), and `originalSide` (the stored side, when
     *   re-anchoring moved the comment across the diff).
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
                    $source = FileSourceSpec::absolute($absolute);
                    $hasHash = $storedHash !== null && $storedHash !== '';

                    if (! $hasHash || $storedHash === $this->gitFileContentService->hashForSource($repoPath, $source)) {
                        $anchorStatus = AnchorStatus::Placed;
                    } elseif ($lineSnippet !== null && $startLine !== null) {
                        // Hash drifted but the snippet may still occur — re-anchor
                        // instead of dropping. The content read returns null when the
                        // file is gone, which the matcher rejects.
                        $shifted = $this->snippetMatcher->shiftedLines(
                            (string) $this->gitFileContentService->contentForSource($repoPath, $source),
                            $lineSnippet,
                            $startLine,
                        );

                        if ($shifted !== null) {
                            $anchorStatus = AnchorStatus::Placed;
                            [$startLine, $endLine] = $shifted;
                        }
                    }
                }
            } elseif ($storedHash !== null && $storedHash !== '' && ! isset($fileIdByPath[$filePath]) && $storedOriginRef === GitRef::Working->value && $target->isWorkingDirectory()) {
                $workingSource = FileSourceSpec::working($filePath);
                $workingHash = $this->gitFileContentService->hashForSource($repoPath, $workingSource);

                if ($storedHash === $workingHash) {
                    $anchorStatus = AnchorStatus::Placed;
                } elseif ($lineSnippet !== null && $startLine !== null) {
                    $shifted = $this->snippetMatcher->shiftedLines(
                        (string) $this->gitFileContentService->contentForSource($repoPath, $workingSource),
                        $lineSnippet,
                        $startLine,
                    );

                    if ($shifted !== null) {
                        $anchorStatus = AnchorStatus::Placed;
                        [$startLine, $endLine] = $shifted;
                    }
                }
            } elseif ($storedHash !== null && $storedHash !== '' && isset($fileIdByPath[$filePath])) {
                // forSide() resolves the rename: left-side content lives at the
                // pre-rename `oldPath`, the right side stays at `path`. Without it,
                // left comments on rename+edit diffs would be stamped unplaced and
                // then silently dropped from submit.
                $leftSource = FileSourceSpec::forSide($target, DiffSide::Left, $filePath, $oldPathByPath[$filePath] ?? null);
                $rightSource = FileSourceSpec::forSide($target, DiffSide::Right, $filePath);
                $leftHash = $this->gitFileContentService->hashForSource($repoPath, $leftSource);
                $rightHash = $this->gitFileContentService->hashForSource($repoPath, $rightSource);

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
                    $recovered = $this->recoverBySnippet($repoPath, $leftSource, $rightSource, $storedSide, $lineSnippet, $startLine);

                    if ($recovered !== null) {
                        [$resolvedSide, $startLine, $endLine] = $recovered;
                        $anchorStatus = AnchorStatus::Placed;
                    }
                }
            } elseif (isset($fileIdByPath[$filePath])) {
                $anchorStatus = AnchorStatus::Placed;
            }

            $resolved[] = Comment::fromArray([
                ...$row,
                'id' => (string) $row['id'],
                'fileId' => $fileId,
                'file' => $filePath,
                'side' => $resolvedSide,
                'originalSide' => $storedSide,
                'startLine' => $startLine,
                'endLine' => $endLine,
                'originRef' => $storedOriginRef,
                'fileContentHash' => $storedHash,
                'lineSnippet' => $lineSnippet,
                'anchorStatus' => $anchorStatus->value,
            ])->toArray();
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
    private function recoverBySnippet(string $repoPath, FileSourceSpec $leftSource, FileSourceSpec $rightSource, string $storedSide, ?string $snippet, ?int $startLine): ?array
    {
        // No snippet means nothing to search for — skip the content fetch entirely
        // (avoids a `git show` per drifted comment that could never re-anchor).
        if ($snippet === null || $snippet === '' || $startLine === null) {
            return null;
        }

        $left = fn (): ?string => $this->gitFileContentService->contentForSource($repoPath, $leftSource);
        $right = fn (): ?string => $this->gitFileContentService->contentForSource($repoPath, $rightSource);

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
