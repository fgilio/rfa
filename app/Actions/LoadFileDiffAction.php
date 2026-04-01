<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileDiff;
use App\Exceptions\GitCommandException;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use App\Services\MarkdownTableAlignerService;
use App\Services\SyntaxHighlightService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
        private SyntaxHighlightService $syntaxHighlightService,
        private MarkdownTableAlignerService $markdownTableAligner,
    ) {}

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool, syntaxStyles: string} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target): array {
            try {
                $rawDiff = $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines, target: $target);
            } catch (GitCommandException $e) {
                Log::warning('Git diff failed', ['path' => $path, 'stderr' => $e->stderr]);

                return FileDiff::emptyArray($path, 'modified', tooLarge: false)
                    + ['error' => 'Failed to load diff for this file.', 'syntaxStyles' => ''];
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

            $newFileLineCount = $fileDiff->status === 'deleted'
                ? null
                : $this->gitDiffService->getNewFileLineCount($repoPath, $path, $target);

            return $fileDiff->withHunks($highlightedHunks)->toArray() + ['tooLarge' => false, 'syntaxStyles' => $css, 'tableAligned' => true, 'newFileLineCount' => $newFileLineCount];
        };

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null && array_key_exists('syntaxStyles', $cached) && array_key_exists('isSymlink', $cached) && array_key_exists('tableAligned', $cached) && array_key_exists('newFileLineCount', $cached)) {
                return $cached;
            }
            $result = $compute();
            Cache::put($cacheKey, $result, now()->addHours($target->cacheTtlHours()));

            return $result;
        }

        return $compute();
    }
}
