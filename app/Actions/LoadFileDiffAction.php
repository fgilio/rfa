<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\LoadedDiff;
use App\Exceptions\GitCommandException;
use App\Services\CsvAlignerService;
use App\Services\DiffParser;
use App\Services\ExternalFilesService;
use App\Services\GitDiffService;
use App\Services\MarkdownRegionService;
use App\Services\MarkdownTableAlignerService;
use App\Services\ReviewConfigService;
use App\Services\SyntaxHighlightService;
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
        ?ReviewConfigService $reviewConfigService = null,
    ) {
        $this->reviewConfigService = $reviewConfigService ?? new ReviewConfigService;
    }

    private readonly ReviewConfigService $reviewConfigService;

    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, ?int $contextLines = null, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): LoadedDiff
    {
        $target ??= DiffTarget::workingDirectory();
        $reviewConfig = $this->reviewConfigService->resolve();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target, $oldPath, $externalAbsolutePath, $reviewConfig): LoadedDiff {
            try {
                $rawDiff = $externalAbsolutePath !== null
                    ? $this->externalFilesService->buildDiff($externalAbsolutePath, $path)
                    : $this->gitDiffService->getFileDiff($repoPath, $path, $isUntracked, contextLines: $contextLines, target: $target, oldPath: $oldPath);
            } catch (GitCommandException $e) {
                Log::warning('git.diff.failed', [
                    'reason' => 'diff_process_failed',
                    'path' => $path,
                    'exit_code' => $e->exitCode,
                    'stderr_summary' => LogSanitizer::summary($e->stderr),
                ]);

                return LoadedDiff::transientError($path);
            }

            if ($rawDiff === null) {
                return LoadedDiff::tooLarge($path);
            }

            if (trim($rawDiff) === '') {
                return LoadedDiff::empty($path);
            }

            // Untracked and external diffs are hand-built (never git-colorized),
            // so their content can hold literal ANSI escapes. Moved-line detection
            // strips ANSI from the whole patch, which would corrupt that content;
            // only enable it for real git-colorized diffs.
            $isHandBuiltDiff = $externalAbsolutePath !== null || ($isUntracked && $target->isWorkingDirectory());

            $fileDiff = $this->diffParser->parseSingle($rawDiff, detectMovedLines: $reviewConfig->movedLineDetection && ! $isHandBuiltDiff);

            if (! $fileDiff) {
                return LoadedDiff::unparsable($path);
            }

            $fileDiff = $fileDiff->withHunks(
                $this->csvAligner->alignRows($fileDiff->hunks, $fileDiff->path)
            );

            $highlightedHunks = $this->syntaxHighlightService->highlightHunks($fileDiff->hunks, $fileDiff->path);
            $annotatedHunks = $this->markdownRegionService->annotate($highlightedHunks, $fileDiff->path);

            // Table grid metadata is attached last so it survives the line
            // reconstruction that highlighting and heading annotation perform.
            $annotatedHunks = $this->markdownTableAligner->alignTables($annotatedHunks, $fileDiff->path);

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

            return LoadedDiff::loaded(
                $fileDiff->withHunks($annotatedHunks),
                syntaxStyles: $css,
                newFileLineCount: $newFileLineCount,
                syntaxHighlighter: $this->syntaxHighlightService->lastHighlighter(),
            );
        };

        if ($cacheKey === null) {
            return $compute();
        }

        $cached = LoadedDiff::tryFrom(Cache::get($cacheKey));

        if ($cached !== null) {
            return $cached;
        }

        $result = $compute();

        if ($result->outcome->isCacheable()) {
            $ttlHours = $target->cacheTtlHours($reviewConfig->cacheTtlHours);

            Cache::put($cacheKey, $result->toArray(), now()->addHours($ttlHours));
        }

        return $result;
    }
}
