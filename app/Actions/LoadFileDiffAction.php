<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileDiff;
use App\Exceptions\GitCommandException;
use App\Services\CsvAlignerService;
use App\Services\DiffParser;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Services\MarkdownRegionService;
use App\Services\MarkdownTableAlignerService;
use App\Services\SyntaxHighlightService;
use App\Support\DiffCacheKey;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final readonly class LoadFileDiffAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
        private DiffParser $diffParser,
        private SyntaxHighlightService $syntaxHighlightService,
        private MarkdownTableAlignerService $markdownTableAligner,
        private CsvAlignerService $csvAligner,
        private MarkdownRegionService $markdownRegionService,
        private ExternalFilesService $externalFilesService,
    ) {}

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool, syntaxStyles: string} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target, $oldPath, $externalAbsolutePath): array {
            try {
                $rawDiff = $externalAbsolutePath !== null
                    ? $this->externalFilesService->buildDiff($externalAbsolutePath, $path)
                    : $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines, target: $target, oldPath: $oldPath);
            } catch (GitCommandException $e) {
                Log::warning('git.diff.failed', [
                    'reason' => 'diff_process_failed',
                    'path' => $path,
                    'stderr_summary' => LogSanitizer::summarize($e->stderr),
                ]);

                return FileDiff::emptyArray($path, 'modified', tooLarge: false)
                    + ['error' => 'Failed to load diff for this file.', 'syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if ($rawDiff === null) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: true) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if (trim($rawDiff) === '') {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            $fileDiff = $this->diffParser->parseSingle($rawDiff);

            if (! $fileDiff) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            $fileDiff = $fileDiff->withHunks(
                $this->markdownTableAligner->alignTables($fileDiff->hunks, $fileDiff->path)
            );

            $fileDiff = $fileDiff->withHunks(
                $this->csvAligner->alignRows($fileDiff->hunks, $fileDiff->path)
            );

            $highlightedHunks = $this->syntaxHighlightService->highlightHunks($fileDiff->hunks, $fileDiff->path);
            $annotatedHunks = $this->markdownRegionService->annotate($highlightedHunks, $fileDiff->path);

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
                : ($externalAbsolutePath !== null
                    ? $this->gitDiffService->countLinesInFile($externalAbsolutePath)
                    : $this->gitDiffService->getNewFileLineCount($repoPath, $path, $target));

            return $fileDiff->withHunks($annotatedHunks)->toArray() + [
                'tooLarge' => false,
                'syntaxStyles' => $css,
                'tableAligned' => true,
                'newFileLineCount' => $newFileLineCount,
                'headingsAnnotated' => true,
                'gridLayout' => true,
                'lineTypesAreEnum' => true,
                'renameAware' => true,
            ];
        };

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if (DiffCacheKey::isCurrentShape($cached)) {
                return $cached;
            }
            $result = $compute();
            Cache::put($cacheKey, $result, now()->addHours($target->cacheTtlHours()));

            return $result;
        }

        return $compute();
    }
}
