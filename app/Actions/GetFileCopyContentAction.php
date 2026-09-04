<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CopyContentResult;
use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Services\ExternalFilesService;
use App\Services\FileSourceService;
use App\Services\GitDiffService;

final readonly class GetFileCopyContentAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private FileSourceService $fileSourceService,
        private ExternalFilesService $externalFilesService,
    ) {}

    public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null, bool $isWholeFile = false): CopyContentResult
    {
        return match ($kind) {
            'diff' => $this->diffContent($repoPath, $path, $isUntracked, $target, $oldPath, $isExternal, $externalAbsolutePath, $isWholeFile),
            'original', 'new' => $this->sideContent($kind, $repoPath, $target, $path, $status, $oldPath, $isUntracked, $isExternal, $externalAbsolutePath),
            default => CopyContentResult::unavailable(),
        };
    }

    /**
     * Build the unified diff for the clipboard, routing external files through
     * the same builder the diff view uses so an out-of-repo file produces its
     * real diff rather than an empty `git diff` against a path the repo lacks.
     * The moved-line colorization getFileDiff adds for the parser is skipped so
     * the clipboard gets a clean patch.
     */
    private function diffContent(string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath, bool $isExternal, ?string $externalAbsolutePath, bool $isWholeFile): CopyContentResult
    {
        $diff = $isExternal && $externalAbsolutePath !== null && $externalAbsolutePath !== ''
            ? $this->externalFilesService->buildDiff($externalAbsolutePath, $path)
            : $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, target: $target, oldPath: $oldPath, detectMovedLines: false, isWholeFile: $isWholeFile);

        return $diff === null || $diff === ''
            ? CopyContentResult::unavailable()
            : CopyContentResult::ok($diff);
    }

    /**
     * Read one side of a file through the same source resolution and fetch path
     * the snapshot export uses, so git, working-tree, and external absolute
     * sources all resolve identically. A side with no source (an added file's
     * original, a deleted file's new) is unavailable, and a source past the
     * size cap reports its size so the caller can say why it was skipped.
     */
    private function sideContent(string $kind, string $repoPath, DiffTarget $target, string $path, string $status, ?string $oldPath, bool $isUntracked, bool $isExternal, ?string $externalAbsolutePath): CopyContentResult
    {
        [$oldSource, $newSource] = FileSourceSpec::pairFor(
            target: $target,
            path: $path,
            status: $status,
            oldPath: $oldPath,
            isUntracked: $isUntracked,
            isExternal: $isExternal,
            externalAbsolutePath: $externalAbsolutePath,
        );

        $source = $kind === 'original' ? $oldSource : $newSource;

        if ($source->isNone()) {
            return CopyContentResult::unavailable();
        }

        $text = $this->fileSourceService->fetch($repoPath, $source);

        if ($text->isTooLarge()) {
            return CopyContentResult::tooLarge($text->byteSize);
        }

        return $text->isLoaded()
            ? CopyContentResult::ok($text->content ?? '')
            : CopyContentResult::unavailable();
    }
}
