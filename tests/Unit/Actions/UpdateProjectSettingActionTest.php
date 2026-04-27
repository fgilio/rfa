<?php

use App\Actions\UpdateProjectSettingAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('updates an allow-listed attribute', function () {
    $project = Project::factory()->create(['branch' => 'main']);

    app(UpdateProjectSettingAction::class)->handle($project->id, ['branch' => 'feature']);

    expect($project->fresh()->branch)->toBe('feature');
});

test('updates multiple allow-listed attributes at once', function () {
    $project = Project::factory()->create([
        'branch' => 'main',
        'respect_global_gitignore' => false,
    ]);

    app(UpdateProjectSettingAction::class)->handle($project->id, [
        'branch' => 'feature-x',
        'respect_global_gitignore' => true,
    ]);

    $fresh = $project->fresh();

    expect($fresh->branch)->toBe('feature-x')
        ->and($fresh->respect_global_gitignore)->toBeTrue();
});

test('drops attributes outside the allow-list', function () {
    $project = Project::factory()->create([
        'name' => 'original',
        'path' => '/tmp/original',
    ]);

    app(UpdateProjectSettingAction::class)->handle($project->id, [
        'name' => 'tampered',
        'path' => '/tmp/tampered',
        'branch' => 'feature',
    ]);

    $fresh = $project->fresh();

    expect($fresh->name)->toBe('original')
        ->and($fresh->path)->toBe('/tmp/original')
        ->and($fresh->branch)->toBe('feature');
});

test('is a no-op when only disallowed attributes are passed', function () {
    $project = Project::factory()->create(['name' => 'original']);
    $originalUpdatedAt = $project->updated_at;

    app(UpdateProjectSettingAction::class)->handle($project->id, ['name' => 'tampered']);

    $fresh = $project->fresh();

    expect($fresh->name)->toBe('original')
        ->and($fresh->updated_at->equalTo($originalUpdatedAt))->toBeTrue();
});

test('silently ignores unknown project ids', function () {
    app(UpdateProjectSettingAction::class)->handle(9_999_999, ['branch' => 'feature']);

    expect(Project::count())->toBe(0);
});
