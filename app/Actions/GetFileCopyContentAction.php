<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Enums\GitRef;
use App\Services\GitDiffService;
use App\Services\GitMetadataService;

final readonly class GetFileCopyContentAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $kind, string $repoPath, string $path, bool $isUntracked, DiffTarget $target, ?string $oldPath = null): ?string
    {
        return match ($kind) {
            // The clipboard needs a clean unified diff, so skip the moved-line
            // colorization that getFileDiff produces for the parser.
            'diff' => $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, target: $target, oldPath: $oldPath, detectMovedLines: false),
            'original' => $this->gitMetadataService->getFileContent($repoPath, $oldPath ?? $path, $target->from()),
            'new' => $this->gitMetadataService->getFileContent($repoPath, $path, $target->to() ?? GitRef::Working->value),
            default => null,
        };
    }
}
