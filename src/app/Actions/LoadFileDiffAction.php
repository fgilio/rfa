<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileDiff;
use App\Exceptions\GitCommandException;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\MarkdownTableAligner;
use App\Services\SyntaxHighlightService;
use Illuminate\Support\Facades\Cache;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
        private SyntaxHighlightService $syntaxHighlightService,
        private MarkdownTableAligner $markdownTableAligner,
    ) {}

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool, syntaxStyles: string} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target): array {
            try {
                $rawDiff = $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines, target: $target);
            } catch (GitCommandException $e) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false)
                    + ['error' => $e->stderr ?: $e->getMessage(), 'syntaxStyles' => ''];
            }

            if ($rawDiff === null) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: true) + ['syntaxStyles' => ''];
            }

            if (trim($rawDiff) === '') {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false) + ['syntaxStyles' => ''];
            }

            $fileDiff = $this->diffParser->parseSingle($rawDiff);

            if (! $fileDiff) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false) + ['syntaxStyles' => ''];
            }

            $fileDiff = $fileDiff->withHunks(
                $this->markdownTableAligner->alignTables($fileDiff->hunks, $fileDiff->path)
            );

            $highlightedHunks = $this->syntaxHighlightService->highlightHunks($fileDiff->hunks, $fileDiff->path);

            $css = '';
            foreach ($this->syntaxHighlightService->getStyleMap() as $cls => $styles) {
                if ($styles['light'] !== '') {
                    $css .= ".{$cls}{{$styles['light']}}";
                }
                if ($styles['dark'] !== '') {
                    $css .= ".dark .{$cls}{{$styles['dark']}}";
                }
            }

            return $fileDiff->withHunks($highlightedHunks)->toArray() + ['tooLarge' => false, 'syntaxStyles' => $css, 'tableAligned' => true];
        };

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && array_key_exists('syntaxStyles', $cached) && array_key_exists('isSymlink', $cached) && array_key_exists('tableAligned', $cached)) {
                return $cached;
            }
            $result = $compute();
            Cache::put($cacheKey, $result, now()->addHours($target->cacheTtlHours()));

            return $result;
        }

        return $compute();
    }
}
