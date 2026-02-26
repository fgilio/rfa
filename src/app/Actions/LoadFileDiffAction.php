<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\FileDiff;
use App\Exceptions\GitCommandException;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\SyntaxHighlightService;
use Illuminate\Support\Facades\Cache;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
        private SyntaxHighlightService $syntaxHighlightService,
    ) {}

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3): array
    {
        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines): array {
            try {
                $rawDiff = $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines);
            } catch (GitCommandException $e) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false)
                    + ['error' => $e->stderr ?: $e->getMessage()];
            }

            if ($rawDiff === null) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: true);
            }

            if (trim($rawDiff) === '') {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false);
            }

            $fileDiff = $this->diffParser->parseSingle($rawDiff);

            if (! $fileDiff) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false);
            }

            $highlightedHunks = $this->syntaxHighlightService->highlightHunks($fileDiff->hunks, $fileDiff->path);

            return $fileDiff->withHunks($highlightedHunks)->toArray() + ['tooLarge' => false];
        };

        if ($cacheKey) {
            return Cache::remember($cacheKey, now()->addHours(config('rfa.cache_ttl_hours', 24)), $compute);
        }

        return $compute();
    }
}
