<?php

use App\Actions\LinkExternalPathAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->action = new LinkExternalPathAction;
    $this->repoDir = $this->createTempDirectory('rfa_link_repo_');
    $this->extDir = $this->createTempDirectory('rfa_link_ext_');

    $this->project = Project::factory()->create([
        'path' => $this->repoDir,
        'git_common_dir' => $this->repoDir.'/.git',
    ]);
});

test('returns null when the project does not exist', function () {
    expect($this->action->handle(99_999, $this->extDir))->toBeNull();
});

test('returns null when the path is not a directory', function () {
    expect($this->action->handle($this->project->id, '/this/path/is/not/real'))->toBeNull();
});

test('appends a new external path with a default label of the directory basename', function () {
    $updated = $this->action->handle($this->project->id, $this->extDir);

    expect($updated)->not->toBeNull();
    expect($updated)->toHaveCount(1);
    expect($updated[0]['label'])->toBe(basename(realpath($this->extDir)));
    expect($updated[0]['path'])->toBe(realpath($this->extDir));

    expect($this->project->fresh()->external_paths)->toEqual($updated);
});

test('honors a custom label override', function () {
    $updated = $this->action->handle($this->project->id, $this->extDir, label: 'design notes');

    expect($updated[0]['label'])->toBe('design notes');
});

test('is idempotent on the same canonical path', function () {
    $first = $this->action->handle($this->project->id, $this->extDir);
    $second = $this->action->handle($this->project->id, $this->extDir);

    expect($first)->toEqual($second);
    expect($this->project->fresh()->external_paths)->toHaveCount(1);
});

test('appends a second distinct path', function () {
    $other = $this->createTempDirectory('rfa_link_ext_other_');

    $this->action->handle($this->project->id, $this->extDir);
    $updated = $this->action->handle($this->project->id, $other);

    expect($updated)->toHaveCount(2);
    expect(collect($updated)->pluck('path')->all())
        ->toContain(realpath($this->extDir))
        ->toContain(realpath($other));
});
