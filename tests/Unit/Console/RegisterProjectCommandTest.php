<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_register_cmd_');
    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/file.txt', "ok\n");
    $this->commitTestRepo($this->repoPath, 'init');
});

test('registers the project and prints its slug on success', function () {
    $this->artisan('rfa:register', ['path' => $this->repoPath])
        ->assertExitCode(0);

    $project = Project::query()->where('path', realpath($this->repoPath))->first();

    expect($project)->not->toBeNull()
        ->and($project->slug)->not->toBeEmpty();
});

test('exits 1 with an error when path is not a git repository', function () {
    $nonGit = $this->createTempDirectory('rfa_register_cmd_nongit_');

    $this->artisan('rfa:register', ['path' => $nonGit])
        ->assertExitCode(1);

    expect(Project::count())->toBe(0);
});
