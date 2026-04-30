<?php

use App\Actions\UnlinkExternalPathAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function () {
    $this->action = new UnlinkExternalPathAction;
    $this->repoDir = $this->createTempDirectory('rfa_unlink_repo_');

    $this->project = Project::factory()->create([
        'path' => $this->repoDir,
        'git_common_dir' => $this->repoDir.'/.git',
        'external_paths' => [
            ['label' => 'a', 'path' => '/tmp/a'],
            ['label' => 'b', 'path' => '/tmp/b'],
            ['label' => 'c', 'path' => '/tmp/c'],
        ],
    ]);
});

test('returns null when the project does not exist', function () {
    expect($this->action->handle(99_999, 0))->toBeNull();
});

test('removes the entry at the given index', function () {
    $updated = $this->action->handle($this->project->id, 1);

    expect($updated)->toHaveCount(2);
    expect(collect($updated)->pluck('label')->all())->toBe(['a', 'c']);

    expect($this->project->fresh()->external_paths)->toEqual($updated);
});

test('treats out-of-range indices as no-ops', function () {
    $updated = $this->action->handle($this->project->id, 10);

    expect($updated)->toHaveCount(3);
    expect(collect($updated)->pluck('label')->all())->toBe(['a', 'b', 'c']);
});
