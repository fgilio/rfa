<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Services\FileSourceService;
use App\Services\GitDiffService;

final readonly class GetFileCopyContentAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private FileSourceService $fileSourceService,
    ) {}

    public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null, string $status = 'modified', bool $isExternal = false, ?string $externalAbsolutePath = null): ?string
    {
        return match ($kind) {
            // The clipboard needs a clean unified diff, so skip the moved-line
            // colorization that getFileDiff produces for the parser.
            'diff' => $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, target: $target, oldPath: $oldPath, detectMovedLines: false),
            'original', 'new' => $this->sideContent($kind, $repoPath, $target, $path, $status, $oldPath, $isUntracked, $isExternal, $externalAbsolutePath),
            default => null,
        };
    }

    /**
     * Read one side of a file through the same source resolution and fetch path
     * the snapshot export uses, so git, working-tree, and external absolute
     * sources all resolve identically. A side with no source (an added file's
     * original, a deleted file's new) yields null.
     */
    private function sideContent(string $kind, string $repoPath, DiffTarget $target, string $path, string $status, ?string $oldPath, bool $isUntracked, bool $isExternal, ?string $externalAbsolutePath): ?string
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
            return null;
        }

        $text = $this->fileSourceService->fetch($repoPath, $source);

        return $text->isLoaded() ? $text->content : null;
    }
}
