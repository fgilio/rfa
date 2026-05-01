<?php

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\ResolveProjectByIdAction;
use App\Actions\ScanDirectoryDialogAction;
use App\Listeners\HandleMenuItemClicked;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\Window;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->capturedUrl = null;

    $window = Mockery::mock(Native\Desktop\Windows\Window::class);
    $window->shouldReceive('url')
        ->andReturnUsing(function (string $url) {
            $this->capturedUrl = $url;
        });

    Window::shouldReceive('get')->with('main')->andReturn($window);
});

function bindOpenRepositoryDialogAction(?Project $project): void
{
    app()->bind(OpenRepositoryDialogAction::class, fn () => new class($project)
    {
        public function __construct(private ?Project $project) {}

        public function handle(): ?Project
        {
            return $this->project;
        }
    });
}

function bindResolveProjectByIdAction(?Project $project): void
{
    app()->bind(ResolveProjectByIdAction::class, fn () => new class($project)
    {
        public function __construct(private ?Project $project) {}

        public function handle(int $id): ?Project
        {
            return $this->project;
        }
    });
}

test('show-context with cached active-project navigates to context-page for that project', function () {
    $project = Project::factory()->create(['slug' => 'rfa']);
    Cache::put('rfa.active-project-id', $project->id);

    bindResolveProjectByIdAction($project);
    bindOpenRepositoryDialogAction(null);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'show-context']));

    expect($this->capturedUrl)->toBe(route('context-page', ['slug' => 'rfa']));
});

test('show-context with cache miss falls back to the file-picker flow', function () {
    Cache::forget('rfa.active-project-id');
    $picked = Project::factory()->create(['slug' => 'picked']);

    bindResolveProjectByIdAction(null);
    bindOpenRepositoryDialogAction($picked);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'show-context']));

    expect($this->capturedUrl)->toBe(route('context-page', ['slug' => 'picked']));
});

test('show-context with stale cached id (project deleted) falls back to the file-picker flow', function () {
    Cache::put('rfa.active-project-id', 999_999);
    $picked = Project::factory()->create(['slug' => 'picked']);

    bindResolveProjectByIdAction(null);
    bindOpenRepositoryDialogAction($picked);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'show-context']));

    expect($this->capturedUrl)->toBe(route('context-page', ['slug' => 'picked']));
});

test('show-context with no cached id and no project picked navigates nowhere', function () {
    Cache::forget('rfa.active-project-id');

    bindResolveProjectByIdAction(null);
    bindOpenRepositoryDialogAction(null);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'show-context']));

    expect($this->capturedUrl)->toBeNull();
});

test('open-repo still routes to review-page', function () {
    $project = Project::factory()->create(['slug' => 'opened']);

    bindOpenRepositoryDialogAction($project);
    bindResolveProjectByIdAction(null);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'open-repo']));

    expect($this->capturedUrl)->toBe(route('review-page', ['slug' => 'opened']));
});

test('scan-directory dispatches its action', function () {
    $stub = new class
    {
        public bool $scanned = false;

        public function handle(): void
        {
            $this->scanned = true;
        }
    };
    app()->instance(ScanDirectoryDialogAction::class, $stub);
    bindOpenRepositoryDialogAction(null);
    bindResolveProjectByIdAction(null);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'scan-directory']));

    expect($stub->scanned)->toBeTrue();
});

test('unknown menu item ids are ignored', function () {
    bindOpenRepositoryDialogAction(null);
    bindResolveProjectByIdAction(null);

    app(HandleMenuItemClicked::class)->handle(new MenuItemClicked(['id' => 'whatever']));

    expect($this->capturedUrl)->toBeNull();
});
