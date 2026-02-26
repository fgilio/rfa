<?php

use App\Actions\LoadFileDiffAction;
use App\Models\Project;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Helpers\DiffFixtureGenerator;

// Post-Blaze thresholds (~1.5x measured baseline) - permanent regression guards
const THRESHOLD_SMALL_DIFF = 40.0;
const THRESHOLD_LARGE_DIFF = 5.0;
const THRESHOLD_WITH_COMMENTS = 5.0;
const THRESHOLD_RE_RENDER = 10.0;

beforeEach(function () {
    $this->project = Project::create([
        'slug' => 'perf-test',
        'name' => 'Perf Test',
        'path' => '/tmp/perf-repo',
        'git_common_dir' => '/tmp/perf-repo/.git',
        'branch' => 'main',
    ]);

    // Mock LoadFileDiffAction so it never touches git
    app()->bind(LoadFileDiffAction::class, fn () => new class
    {
        public function handle(string $repoPath, string $path, bool $isUntracked = false, ?string $cacheKey = null, int $contextLines = 3): array
        {
            return DiffFixtureGenerator::diffData(path: $path);
        }
    });
});

function renderDiffFile(array $file, int $projectId, array $diffData, array $comments = []): float
{
    $cacheKey = DiffCacheKey::for($projectId, $file['id']);
    Cache::put($cacheKey, $diffData, 3600);

    $start = hrtime(true);

    Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => '/tmp/perf-repo',
        'projectId' => $projectId,
        'fileComments' => $comments,
    ]);

    return (hrtime(true) - $start) / 1_000_000;
}

// -- Single DiffFile render benchmarks --

test('small diff renders within threshold', function () {
    $file = DiffFixtureGenerator::fileEntry('src/Small.php', 'modified', 2, 1);
    $diffData = DiffFixtureGenerator::diffData(hunks: 1, linesPerHunk: 10, path: 'src/Small.php');

    $ms = renderDiffFile($file, $this->project->id, $diffData);

    expect($ms)->toRenderWithin(THRESHOLD_SMALL_DIFF);
})->group('perf');

test('large diff renders within threshold', function () {
    $file = DiffFixtureGenerator::fileEntry('src/Large.php', 'modified', 60, 40);
    $diffData = DiffFixtureGenerator::diffData(hunks: 5, linesPerHunk: 60, path: 'src/Large.php');

    $ms = renderDiffFile($file, $this->project->id, $diffData);

    expect($ms)->toRenderWithin(THRESHOLD_LARGE_DIFF);
})->group('perf');

test('diff with comments renders within threshold', function () {
    $file = DiffFixtureGenerator::fileEntry('src/Commented.php', 'modified', 15, 8);
    $diffData = DiffFixtureGenerator::diffData(hunks: 2, linesPerHunk: 30, path: 'src/Commented.php');
    $comments = DiffFixtureGenerator::comments($file['id'], 10);

    $ms = renderDiffFile($file, $this->project->id, $diffData, $comments);

    expect($ms)->toRenderWithin(THRESHOLD_WITH_COMMENTS);
})->group('perf');

test('re-render after hydration within threshold', function () {
    $file = DiffFixtureGenerator::fileEntry('src/Rerender.php', 'modified', 10, 5);
    $diffData = DiffFixtureGenerator::diffData(hunks: 2, linesPerHunk: 20, path: 'src/Rerender.php');
    $cacheKey = DiffCacheKey::for($this->project->id, $file['id']);
    Cache::put($cacheKey, $diffData, 3600);

    $component = Livewire::test('diff-file', [
        'file' => $file,
        'repoPath' => '/tmp/perf-repo',
        'projectId' => $this->project->id,
        'fileComments' => [],
    ]);

    $comments = DiffFixtureGenerator::comments($file['id'], 5);

    $start = hrtime(true);
    $component->call('updateComments', $comments);
    $ms = (hrtime(true) - $start) / 1_000_000;

    expect($ms)->toRenderWithin(THRESHOLD_RE_RENDER);
})->group('perf');
