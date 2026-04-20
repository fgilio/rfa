<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\GetFileListAction;
use App\Actions\LoadFileDiffAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
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
     * @return array<string, float>
     */
    public function measureAll(int $rounds = 7, int $warmupRounds = 2): array
    {
        $results = [];

        foreach ($this->scenarios() as $name => $scenario) {
            $results[$name] = $this->measureScenario($scenario, $rounds, $warmupRounds);
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
     * @return array<string, callable(): float>
     */
    private function scenarios(): array
    {
        return [
            'diff-small' => fn (): float => $this->measureDiffFileRender(
                DiffFixtureFactory::fileEntry('src/Small.php', 'modified', 2, 1),
                DiffFixtureFactory::diffData(hunks: 1, linesPerHunk: 10, path: 'src/Small.php'),
            ),
            'diff-large' => fn (): float => $this->measureDiffFileRender(
                DiffFixtureFactory::fileEntry('src/Large.php', 'modified', 60, 40),
                DiffFixtureFactory::diffData(hunks: 5, linesPerHunk: 60, path: 'src/Large.php'),
            ),
            'diff-with-comments' => function (): float {
                $file = DiffFixtureFactory::fileEntry('src/Commented.php', 'modified', 15, 8);

                return $this->measureDiffFileRender(
                    $file,
                    DiffFixtureFactory::diffData(hunks: 2, linesPerHunk: 30, path: 'src/Commented.php'),
                    DiffFixtureFactory::comments($file['id'], 10),
                );
            },
            'review-page-20-files' => fn (): float => $this->measureReviewPageRender(20),
            'review-page-50-files' => fn (): float => $this->measureReviewPageRender(50),
            'review-page-100-files' => fn (): float => $this->measureReviewPageRender(100),
            'flux-500-mixed' => fn (): float => $this->measureBladeRender(
                '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
                ['count' => 500],
            ),
            'flux-2000-mixed' => fn (): float => $this->measureBladeRender(
                '@for($i = 0; $i < $count; $i++) <flux:badge size="sm">Badge {{ $i }}</flux:badge> <flux:icon name="check" variant="mini" /> <flux:text>Text {{ $i }}</flux:text> @endfor',
                ['count' => 2000],
            ),
            'flux-500-nested' => fn (): float => $this->measureBladeRender(
                '@for($i = 0; $i < $count; $i++) <flux:tooltip content="Tooltip {{ $i }}"><flux:button size="sm"><flux:icon name="star" variant="mini" /> Action {{ $i }}</flux:button></flux:tooltip> @endfor',
                ['count' => 500],
            ),
        ];
    }

    private function measureScenario(callable $scenario, int $rounds, int $warmupRounds): float
    {
        for ($i = 0; $i < $warmupRounds; $i++) {
            $scenario();
        }

        $measurements = [];

        for ($i = 0; $i < $rounds; $i++) {
            $measurements[] = $scenario();
        }

        return PerfBenchmarkStatistics::median(
            PerfBenchmarkStatistics::filterOutliers($measurements)
        );
    }

    /**
     * @param  FileEntryData  $file
     * @param  DiffData  $diffData
     * @param  list<CommentData>  $comments
     */
    private function measureDiffFileRender(array $file, array $diffData, array $comments = []): float
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
            ): array {
                return $this->diffData;
            }
        });

        $cacheKey = DiffCacheKey::for($project->id, $file['id']);
        Cache::put($cacheKey, $diffData, 3600);

        $start = hrtime(true);

        Livewire::test('diff-file', [
            'file' => $file,
            'repoPath' => '/tmp/perf-repo',
            'projectId' => $project->id,
            'fileComments' => $comments,
        ]);

        return (hrtime(true) - $start) / 1_000_000;
    }

    private function measureReviewPageRender(int $fileCount): float
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

        $this->app->bind(RestoreSessionAction::class, fn () => new class
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
        });

        $this->app->bind(SaveSessionAction::class, fn () => new class
        {
            public function handle(
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

        $start = hrtime(true);

        Livewire::test('pages::review-page', ['slug' => 'perf-review']);

        return (hrtime(true) - $start) / 1_000_000;
    }

    /** @param  array<string, int>  $data */
    private function measureBladeRender(string $template, array $data): float
    {
        $this->resetState();

        $start = hrtime(true);
        Blade::render($template, $data);

        return (hrtime(true) - $start) / 1_000_000;
    }

    private function resetState(): void
    {
        app(BenchmarkIsolation::class)->ensureActive();

        Cache::flush();

        ReviewSession::query()->delete();
        Project::query()->delete();
    }
}
