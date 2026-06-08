<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileDiff;
use App\DTOs\FileSourceSpec;
use App\Enums\GitRef;
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

    /** @return array{path: string, status: string, oldPath: ?string, hunks: array<int, array<string, mixed>>, additions: int, deletions: int, isBinary: bool, tooLarge: bool, skipReason?: ?string, syntaxStyles: string, oldSource?: ?array<string, mixed>, newSource?: ?array<string, mixed>} */
    public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3, ?DiffTarget $target = null, ?string $oldPath = null, ?string $externalAbsolutePath = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        $compute = function () use ($repoPath, $path, $isUntracked, $contextLines, $target, $oldPath, $externalAbsolutePath): array {
            [$fallbackOldSource, $fallbackNewSource] = $this->sourceSpecs(
                path: $path,
                target: $target,
                status: 'modified',
                isUntracked: $isUntracked,
                oldPath: $oldPath,
                externalAbsolutePath: $externalAbsolutePath,
            );

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

                return FileDiff::emptyArray($path, 'modified', tooLarge: false, oldSource: $fallbackOldSource, newSource: $fallbackNewSource)
                    + ['error' => 'Failed to load diff for this file.', 'syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if ($rawDiff === null) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: true, skipReason: 'too-large', oldSource: $fallbackOldSource, newSource: $fallbackNewSource) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            if (trim($rawDiff) === '') {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false, oldSource: $fallbackOldSource, newSource: $fallbackNewSource) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            $fileDiff = $this->diffParser->parseSingle($rawDiff);

            if (! $fileDiff) {
                return FileDiff::emptyArray($path, 'modified', tooLarge: false, oldSource: $fallbackOldSource, newSource: $fallbackNewSource) + ['syntaxStyles' => '', 'headingsAnnotated' => true];
            }

            [$oldSource, $newSource] = $this->sourceSpecs(
                path: $fileDiff->path,
                target: $target,
                status: $fileDiff->status,
                isUntracked: $isUntracked,
                oldPath: $fileDiff->oldPath ?? $oldPath,
                externalAbsolutePath: $externalAbsolutePath,
            );

            $fileDiff = $fileDiff->withSourceSpecs($oldSource, $newSource);

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
            $ttlHours = $target->isImmutable()
                ? 720
                : $this->reviewConfigService->resolve()->cacheTtlHours;

            Cache::put($cacheKey, $result, now()->addHours($ttlHours));

            return $result;
        }

        return $compute();
    }

    /** @return array{0: FileSourceSpec, 1: FileSourceSpec} */
    private function sourceSpecs(string $path, DiffTarget $target, string $status, bool $isUntracked, ?string $oldPath, ?string $externalAbsolutePath): array
    {
        if ($externalAbsolutePath !== null) {
            return [
                FileSourceSpec::none(),
                FileSourceSpec::absolute($externalAbsolutePath),
            ];
        }

        $oldSource = $status === 'added' || $isUntracked
            ? FileSourceSpec::none()
            : FileSourceSpec::git($target->from(), $oldPath ?? $path);

        $newSource = $status === 'deleted'
            ? FileSourceSpec::none()
            : FileSourceSpec::git($target->to() ?? GitRef::Working->value, $path);

        return [$oldSource, $newSource];
    }
}
