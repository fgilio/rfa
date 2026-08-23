<?php

use App\Actions\RecordProjectEntryAction;
use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->action = app(RecordProjectEntryAction::class);
    $this->startup = app(ResolveStartupRouteAction::class);

    Cache::forget('rfa.active-project-id');
    $this->startup->forgetLastOpened();
});

test('records both the active project id and the last-opened slug', function () {
    $this->action->handle(42, 'my-repo');

    expect($this->action->activeProjectId())->toBe(42)
        ->and($this->startup->lastOpenedSlug())->toBe('my-repo');
});

test('keeps the two values on their own cache keys and lifetimes', function () {
    $this->action->handle(42, 'my-repo');

    // The active id expires within the day; the startup slug never does.
    expect(Cache::get('rfa.active-project-id'))->toBe(42)
        ->and(Cache::get('last-opened-project-slug'))->toBe('my-repo');
});

test('a later entry replaces the previous project identity', function () {
    $this->action->handle(1, 'first-repo');
    $this->action->handle(2, 'second-repo');

    expect($this->action->activeProjectId())->toBe(2)
        ->and($this->startup->lastOpenedSlug())->toBe('second-repo');
});

test('forgetActiveProject drops the menu handle without losing the startup slug', function () {
    $this->action->handle(42, 'my-repo');

    $this->action->forgetActiveProject();

    expect($this->action->activeProjectId())->toBeNull()
        ->and($this->startup->lastOpenedSlug())->toBe('my-repo');
});

test('activeProjectId reports nothing when the cached value expired', function () {
    expect($this->action->activeProjectId())->toBeNull();
});

test('a deleted project leaves no entry behind for startup to restore', function () {
    $project = Project::factory()->create(['slug' => 'doomed']);

    $this->action->handle($project->id, $project->slug);
    $project->delete();

    expect($this->startup->lastOpenedSlug())->toBeNull()
        ->and($this->startup->handle())->toBe(route('select-repo'));
});
