<?php

use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Models\Project;
use App\Services\GitDiffService;
use Livewire\Livewire;
use Tests\Helpers\DiffFixtureGenerator;

// Post-Blaze thresholds - CI-calibrated regression guards
const THRESHOLD_20_FILES = 200.0;
const THRESHOLD_50_FILES = 460.0;
const THRESHOLD_100_FILES = 900.0;

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'perf-review',
        'name' => 'Perf Review',
        'path' => '/tmp/perf-repo',
        'git_common_dir' => '/tmp/perf-repo/.git',
        'branch' => 'main',
        'global_gitignore_path' => '/tmp/test-global-gitignore',
        'respect_global_gitignore' => true,
    ]);

    $project = $this->project;

    app()->bind(ResolveProjectAction::class, fn () => new class($project)
    {
        public function __construct(private Project $project) {}

        public function handle(string $slug): array
        {
            return $this->project->toArray();
        }
    });

    app()->bind(RestoreSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $currentFiles, ?int $projectId = null): array
        {
            return ['comments' => [], 'viewedFiles' => [], 'globalComment' => ''];
        }
    });

    app()->bind(SaveSessionAction::class, fn () => new class
    {
        public function handle(string $repoPath, array $comments, array $viewedFiles, string $globalComment, ?int $projectId = null): void {}
    });

    app()->bind(GitDiffService::class, fn () => new class
    {
        public function resolveGlobalExcludesFile(string $repoPath): ?string
        {
            return null;
        }
    });
});

function bindFileList(int $count): void
{
    $files = DiffFixtureGenerator::fileEntries($count);

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null, ?string $globalGitignorePath = null): array
        {
            return $this->files;
        }
    });
}

function renderReviewPage(): float
{
    $start = hrtime(true);
    Livewire::test('pages::review-page', ['slug' => 'perf-review']);

    return (hrtime(true) - $start) / 1_000_000;
}

// -- ReviewPage render benchmarks --

test('standard review (20 files) renders within threshold', function () {
    bindFileList(20);
    $ms = renderReviewPage();

    expect($ms)->toRenderWithin(THRESHOLD_20_FILES);
})->group('perf');

test('intense review (50 files) renders within threshold', function () {
    bindFileList(50);
    $ms = renderReviewPage();

    expect($ms)->toRenderWithin(THRESHOLD_50_FILES);
})->group('perf');

test('extreme review (100 files) renders within threshold', function () {
    bindFileList(100);
    $ms = renderReviewPage();

    expect($ms)->toRenderWithin(THRESHOLD_100_FILES);
})->group('perf');
