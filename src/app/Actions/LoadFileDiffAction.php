<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\FileDiff;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\SyntaxHighlightService;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
        private SyntaxHighlightService $syntaxHighlightService,
    ) {}

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool}|null */
    public function handle(string $repoPath, string $path, bool $isUntracked = false): ?array
    {
        $rawDiff = $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked);

        if ($rawDiff === null) {
            return FileDiff::emptyArray($path, 'modified', tooLarge: true);
        }

        if (trim($rawDiff) === '') {
            return null;
        }

        $fileDiff = $this->diffParser->parseSingle($rawDiff);

        if (! $fileDiff) {
            return null;
        }

        $highlightedHunks = $this->syntaxHighlightService->highlightHunks($fileDiff->hunks, $fileDiff->path);

        return $fileDiff->withHunks($highlightedHunks)->toArray() + ['tooLarge' => false];
    }
}
