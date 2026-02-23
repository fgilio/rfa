<?php

use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Livewire\ReviewPage;
use Livewire\Livewire;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->files = [
        ['id' => 'abc123', 'path' => 'src/Foo.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 5, 'deletions' => 2, 'isBinary' => false, 'isUntracked' => false],
        ['id' => 'def456', 'path' => 'src/Bar.php', 'status' => 'modified', 'oldPath' => null, 'additions' => 3, 'deletions' => 1, 'isBinary' => false, 'isUntracked' => false],
    ];

    $files = $this->files;

    app()->bind(ResolveProjectAction::class, fn () => new class
    {
        public function handle(string $slug): array
        {
            return [
                'path' => '/tmp/repo',
                'id' => 1,
                'name' => 'Test Project',
                'branch' => 'main',
                'slug' => 'test-project',
            ];
        }
    });

    app()->bind(GetFileListAction::class, fn () => new class($files)
    {
        public function __construct(private array $files) {}

        public function handle(string $repoPath, bool $clearCache = true, ?int $projectId = null): array
        {
            return $this->files;
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
});

test('toggleViewed updates viewedFiles state', function () {
    $component = Livewire::test(ReviewPage::class, ['slug' => 'test-project'])
        ->dispatch('toggle-viewed', filePath: 'src/Foo.php');

    expect($component->get('viewedFiles'))->toBe(['src/Foo.php']);
});

test('toggleViewed skips parent re-render', function () {
    $component = Livewire::test(ReviewPage::class, ['slug' => 'test-project'])
        ->dispatch('toggle-viewed', filePath: 'src/Foo.php');

    expect(\Livewire\store($component->instance())->get('skipRender'))->toBeTrue();
});
