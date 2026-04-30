<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class, InteractsWithTestRepositories::class);

beforeEach(function () {
    $this->repoDir = $this->createTempDirectory('rfa_link_cmd_repo_');
    $this->extDir = $this->createTempDirectory('rfa_link_cmd_ext_');

    $this->project = Project::factory()->create([
        'slug' => 'demo',
        'name' => 'Demo',
        'path' => $this->repoDir,
        'git_common_dir' => $this->repoDir.'/.git',
    ]);
});

test('links a path by project slug', function () {
    $this->artisan('rfa:link-path', ['project' => 'demo', 'path' => $this->extDir])
        ->assertSuccessful();

    expect($this->project->fresh()->external_paths)->toHaveCount(1);
});

test('links a path by project id with a custom label', function () {
    $this->artisan('rfa:link-path', ['project' => (string) $this->project->id, 'path' => $this->extDir, '--label' => 'design notes'])
        ->assertSuccessful();

    $stored = $this->project->fresh()->external_paths;
    expect($stored[0]['label'])->toBe('design notes');
});

test('fails cleanly when the project cannot be found', function () {
    $this->artisan('rfa:link-path', ['project' => 'nonexistent', 'path' => $this->extDir])
        ->assertFailed();
});

test('fails cleanly when the path is not a directory', function () {
    $this->artisan('rfa:link-path', ['project' => 'demo', 'path' => '/this/path/does/not/exist'])
        ->assertFailed();
});
