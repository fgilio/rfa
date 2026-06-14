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
use App\Services\ReviewConfigService;
use App\Services\SyntaxHighlightService;
use App\Support\DiffCacheKey;
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
        ?ReviewConfigService $reviewConfigService = null,
    ) {
        $this->reviewConfigService = $reviewConfigService ?? new ReviewConfigService;
    }

    private readonly ReviewConfigService $reviewConfigService;

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool, skipReason?: ?string, syntaxStyles: string} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, ?int $contextLines = null, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): array
    {
        $target ??= DiffTarget::workingDirectory();
        $reviewConfig = $this->reviewConfigService->resolve();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target, $oldPath, $externalAbsolutePath, $reviewConfig): array {
            try {
                $rawDiff = $externalAbsolutePath !== null
                    ? $this->externalFilesService->buildDiff($externalAbsolutePath, $path)
                    : $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines, target: $target, oldPath: $oldPath);
            } catch (GitCommandException $e) {
                Log::warning('git.diff.failed', [
                    'reason' => 'diff_process_failed',
                    'path' => $path,
                    'stderr' => $e->stderr,
                ]);

                return FileDiff::emptyArray($path, 'modified', tooLarge: false)
                    + ['error' => 'Failed to load diff for this file.', 'syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if ($rawDiff === null) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: true, skipReason: 'too-large') + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if (trim($rawDiff) === '') {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            // Untracked and external diffs are hand-built (never git-colorized),
            // so their content can hold literal ANSI escapes. Moved-line detection
            // strips ANSI from the whole patch, which would corrupt that content;
            // only enable it for real git-colorized diffs.
            $isHandBuiltDiff = $externalAbsolutePath !== null || ($isUntracked && $target->isWorkingDirectory());

            $fileDiff = $this->diffParser->parseSingle($rawDiff, detectMovedLines: $reviewConfig->movedLineDetection && ! $isHandBuiltDiff);

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
                'skipReason' => null,
                'syntaxStyles' => $css,
                'tableAligned' => true,
                'newFileLineCount' => $newFileLineCount,
                'headingsAnnotated' => true,
                'gridLayout' => true,
                'lineTypesAreEnum' => true,
                'renameAware' => true,
                'syntaxHighlighter' => $this->syntaxHighlightService->lastHighlighter(),
            ];
        };

        if ($cacheKey) {
            $cached = Cache::get($cacheKey);
            if (DiffCacheKey::isCurrentShape($cached)) {
                return $cached;
            }
            $result = $compute();

            // A git failure is transient, so don't persist it — the next read
            // should retry rather than serve a cached error for the full TTL.
            // Skip results (too-large/empty/no-parse) are deterministic and do
            // carry the current cache shape, so they cache and stop re-spawning git.
            if (! array_key_exists('error', $result)) {
                $ttlHours = $target->isImmutable()
                    ? 720
                    : $reviewConfig->cacheTtlHours;

                Cache::put($cacheKey, $result, now()->addHours($ttlHours));
            }

            return $result;
        }

        return $compute();
    }
}
