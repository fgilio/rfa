<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\DiffParser;
use App\Services\GitDiffService;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
    ) {}

    /** @return array{hunks: array<int, array<string, mixed>>, tooLarge: bool}|null */
    public function handle(string $repoPath, string $path, bool $isUntracked = false): ?array
    {
        $rawDiff = $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked);

        if ($rawDiff === null) {
            return ['hunks' => [], 'tooLarge' => true];
        }

        if (trim($rawDiff) === '') {
            return null;
        }

        $fileDiff = $this->diffParser->parseSingle($rawDiff);

        if (! $fileDiff) {
            return null;
        }

        return ['hunks' => $fileDiff->toArray()['hunks'], 'tooLarge' => false];
    }
}
