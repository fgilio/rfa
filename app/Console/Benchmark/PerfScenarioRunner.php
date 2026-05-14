<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetFileListAction;
use App\Actions\LoadFileDiffAction;
use App\Actions\ResolveProjectAction;
use App\Actions\SessionStateAction;
use App\DTOs\DiffTarget;
use App\Models\Project;
use App\Models\ReviewSession;
use App\Support\DiffCacheKey;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

/**
 * @phpstan-import-type CommentData from DiffFixtureFactory
 * @phpstan-import-type DiffData from DiffFixtureFactory
 * @phpstan-import-type FileEntryData from DiffFixtureFactory
 */
final class PerfScenarioRunner
{
    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @return array<string, array{median_ms: float, median_peak_mb: float, median_retained_mb: float}>
     */
    public function measureAll(int $rounds = 7, int $warmupRounds = 2): array
    {
        $results = [];
        $diagnosticsEnabled = config('rfa.diagnostics.enabled');

        config(['rfa.diagnostics.enabled' => false]);

        try {
            foreach ($this->scenarios() as $name => $scenario) {
                $results[$name] = $this->measureScenario($scenario, $rounds, $warmupRounds);
            }
        } finally {
            config(['rfa.diagnostics.enabled' => $diagnosticsEnabled]);
        }

        return $results;
    }

    /**
     * @return list<string>
     */
    public function scenarioNames(): array
    {
        return array_keys($this->scenarios());
    }

    /**
     * @return array<string, callable(): void>
     */
    private function scenarios(): array
    {
        return [
            'diff-small' => function (): void {
                $this->renderDiffFile(
                    DiffFixtureFactory::fileEntry('src/Small.php', 'modified', 2, 1),
                    DiffFixtureFactory::diffData(hunks: 1, linesPerHunk: 10, path: 'src/Small.php'),
                );
            },
            'diff-large' => function (): void {
                $this->renderDiffFile(
                    DiffFixtureFactory::fileEntry('src/Large.php', 'modified', 60, 40),
                    DiffFixtureFactory::diffData(hunks: 5, linesPerHunk: 60, path: 'src/Large.php'),
                );
            },
            'diff-with-comments' => function (): void {
                $file = DiffFixtureFactory::fileEntry('src/Commented.php', 'modified', 15, 8);

                $this->renderDiffFile(
                    $file,
                    DiffFixtureFactory::diffData(hunks: 2, linesPerHunk: 30, path: 'src/Commented.php'),
                    DiffFixtureFactory::comments($file['id'], 10),
                );
            },
            'review-page-20-files' => function (): void {
                $this->renderReviewPage(20);
            },
            'review-page-50-files' => function (): void {
                $this->renderReviewPage(50);
            },
            'review-page-100-files' => function (): void {
                $this->renderReviewPage(100);
            },
            'flux-500-mixed' => function (): void {
                $this->renderBlade(
                    '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
                    ['count' => 500],
                );
            },
            'flux-2000-mixed' => function (): void {
                $this->renderBlade(
                    '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
                    ['count' => 2000],
                );
            },
            'flux-500-nested' => function (): void {
                $this->renderBlade(
                    '@for($i = 0; $i < $count; $i++) <flux:tooltip content="Tooltip {{ $i }}"><flux:button size="sm"><flux:icon name="star" variant="mini" /> Action {{ $i }}</flux:button></flux:tooltip> @endfor',
                    ['count' => 500],
                );
            },
        ];
    }

    /**
     * @param  callable(): void  $scenario
     * @return array{median_ms: float, median_peak_mb: float, median_retained_mb: float}
     */
    private function measureScenario(callable $scenario, int $rounds, int $warmupRounds): array
    {
        for ($i = 0; $i < $warmupRounds; $i++) {
            $scenario();
        }

        $milliseconds = [];
        $peakMegabytes = [];
        $retainedMegabytes = [];

        for ($i = 0; $i < $rounds; $i++) {
            $measurement = $this->measureRound($scenario);

            $milliseconds[] = $measurement['ms'];
            $peakMegabytes[] = $measurement['peak_mb'];
            $retainedMegabytes[] = $measurement['retained_mb'];
        }

        return [
            'median_ms' => round(PerfBenchmarkStatistics::median(
                PerfBenchmarkStatistics::filterOutliers($milliseconds),
            ), 3),
            'median_peak_mb' => round(PerfBenchmarkStatistics::median(
                PerfBenchmarkStatistics::filterOutliers($peakMegabytes),
            ), 3),
            'median_retained_mb' => round(PerfBenchmarkStatistics::median(
                PerfBenchmarkStatistics::filterOutliers($retainedMegabytes),
            ), 3),
        ];
    }

    /**
     * @param  callable(): void  $scenario
     * @return array{ms: float, peak_mb: float, retained_mb: float}
     */
    private function measureRound(callable $scenario): array
    {
        gc_collect_cycles();

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $startingMemory = memory_get_usage(true);
        $startedAt = hrtime(true);

        $scenario();

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
        $peakMemory = max(0, memory_get_peak_usage(true) - $startingMemory);
        $retainedMemory = memory_get_usage(true) - $startingMemory;

        return [
            'ms' => round($durationMs, 3),
            'peak_mb' => round($peakMemory / 1024 / 1024, 3),
            'retained_mb' => round($retainedMemory / 1024 / 1024, 3),
        ];
    }

    /**
     * @param  FileEntryData  $file
     * @param  DiffData  $diffData
     * @param  list<CommentData>  $comments
     */
    private function renderDiffFile(array $file, array $diffData, array $comments = []): void
    {
        $this->resetState();

        $project = Project::create([
            'slug' => 'perf-test',
            'name' => 'Perf Test',
            'path' => '/tmp/perf-repo',
            'git_common_dir' => '/tmp/perf-repo/.git',
            'branch' => 'main',
        ]);

        $this->app->bind(LoadFileDiffAction::class, fn () => new class($diffData)
        {
            /** @param  array<string, mixed>  $diffData */
            public function __construct(private readonly array $diffData) {}

            /** @return array<string, mixed> */
            public function handle(
                string $repoPath,
                string $path,
                bool $isUntracked = false,
                ?string $cacheKey = null,
                int $contextLines = 3,
                ?DiffTarget $target = null,
                ?string $oldPath = null,
                ?string $externalAbsolutePath = null,
            ): array {
                return $this->diffData;
            }
        });

        $cacheKey = DiffCacheKey::for($project->id, $file['id']);
        Cache::put($cacheKey, $diffData, 3600);

        // Mount-only renders the loading skeleton; `loadFileDiff` triggers the actual diff-grid + syntax-highlight work.
        Livewire::test('diff-file', [
            'file' => $file,
            'repoPath' => '/tmp/perf-repo',
            'projectId' => $project->id,
            'fileComments' => $comments,
        ])->call('loadFileDiff');
    }

    private function renderReviewPage(int $fileCount): void
    {
        $this->resetState();

        $project = Project::create([
            'slug' => 'perf-review',
            'name' => 'Perf Review',
            'path' => '/tmp/perf-repo',
            'git_common_dir' => '/tmp/perf-repo/.git',
            'branch' => 'main',
            'global_gitignore_path' => '/tmp/test-global-gitignore',
            'respect_global_gitignore' => true,
        ]);

        $files = DiffFixtureFactory::fileEntries($fileCount);

        $this->app->bind(ResolveProjectAction::class, fn () => new class($project)
        {
            public function __construct(private readonly Project $project) {}

            /** @return array<string, mixed> */
            public function handle(string $slug, bool $touch = false): array
            {
                return $this->project->toArray();
            }
        });

        $this->app->bind(SessionStateAction::class, fn () => new class
        {
            /**
             * @param  array<int, array<string, mixed>>  $currentFiles
             * @return array{comments: array<int, array<string, mixed>>, reviewedFiles: array<string, string>, globalComment: string, orphanedPaths: array<int, string>}
             */
            public function handle(
                string $repoPath,
                array $currentFiles,
                ?int $projectId = null,
                ?DiffTarget $target = null,
            ): array {
                return ['comments' => [], 'reviewedFiles' => [], 'globalComment' => '', 'orphanedPaths' => []];
            }

            public function saveGlobalNote(
                string $repoPath,
                string $globalComment,
                ?int $projectId = null,
            ): void {}
        });

        $this->app->bind(BackfillGlobalGitignoreAction::class, fn () => new class
        {
            public function handle(int $projectId, string $repoPath): null
            {
                return null;
            }
        });

        $this->app->bind(GetFileListAction::class, fn () => new class($files)
        {
            /** @param  list<array<string, mixed>>  $files */
            public function __construct(private readonly array $files) {}

            /** @return list<array<string, mixed>> */
            public function handle(
                string $repoPath,
                bool $clearCache = true,
                ?int $projectId = null,
                ?string $globalGitignorePath = null,
                ?DiffTarget $target = null,
            ): array {
                return $this->files;
            }
        });

        Livewire::test('pages::review-page', ['slug' => 'perf-review']);
    }

    /** @param  array<string, int>  $data */
    private function renderBlade(string $template, array $data): void
    {
        $this->resetState();

        Blade::render($template, $data);
    }

    private function resetState(): void
    {
        app(BenchmarkIsolation::class)->ensureActive();

        Cache::flush();

        ReviewSession::query()->delete();
        Project::query()->delete();
    }
}
