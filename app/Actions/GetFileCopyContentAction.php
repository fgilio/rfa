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
            'diff' => $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, target: $target),
            'original' => $this->gitMetadataService->getFileContent($repoPath, $oldPath ?? $path, $target->from()),
            'new' => $this->gitMetadataService->getFileContent($repoPath, $path, $target->to() ?? GitRef::Working->value),
            default => null,
        };
    }
}
