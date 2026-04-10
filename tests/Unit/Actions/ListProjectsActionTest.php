<?php

use App\Actions\ListProjectsAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

function searchNames(string $query): array
{
    return collect(app(ListProjectsAction::class)->handle('recent', $query)['groups'])->flatten(1)->pluck('name')->all();
}

// -- search ranking --

test('ranks name-prefix above branch match', function () {
    Project::factory()->create(['name' => 'rfa-app', 'git_common_dir' => '/tmp/shared/.git']);
    Project::factory()->create(['name' => 'other', 'branch' => 'rfa-branch', 'git_common_dir' => '/tmp/shared/.git']);

    expect(searchNames('rfa'))->toBe(['rfa-app', 'other']);
});

test('ranks word-boundary above substring', function () {
    Project::factory()->create(['name' => 'farfalla', 'git_common_dir' => '/tmp/shared/.git']);
    Project::factory()->create(['name' => 'my-rfa-tool', 'git_common_dir' => '/tmp/shared/.git']);

    expect(searchNames('rfa'))->toBe(['my-rfa-tool', 'farfalla']);
});

test('ranks branch match above path-only match', function () {
    Project::factory()->create(['name' => 'path-match', 'path' => '/tmp/rfa-stuff', 'git_common_dir' => '/tmp/shared/.git']);
    Project::factory()->create(['name' => 'branch-match', 'branch' => 'rfa-fix', 'git_common_dir' => '/tmp/shared/.git']);

    expect(searchNames('rfa'))->toBe(['branch-match', 'path-match']);
});

test('search is case insensitive', function () {
    Project::factory()->create(['name' => 'RFA']);

    expect(searchNames('rfa'))->toBe(['RFA']);
});

test('equal-rank projects sort alphabetically by name', function () {
    Project::factory()->create(['name' => 'zeta-rfa', 'git_common_dir' => '/tmp/shared/.git']);
    Project::factory()->create(['name' => 'alpha-rfa', 'git_common_dir' => '/tmp/shared/.git']);

    expect(searchNames('rfa'))->toBe(['alpha-rfa', 'zeta-rfa']);
});

// -- handle with search --

test('handle filters projects by search', function () {
    Project::factory()->create(['name' => 'rfa']);
    Project::factory()->create(['name' => 'unrelated']);

    $result = app(ListProjectsAction::class)->handle('recent', 'rfa');

    expect(searchNames('rfa'))->toBe(['rfa'])
        ->and($result['total'])->toBe(2);
});

test('handle ranks exact match above substring', function () {
    Project::factory()->create(['name' => 'farfalla']);
    Project::factory()->create(['name' => 'rfa']);

    expect(searchNames('rfa'))->toBe(['rfa', 'farfalla']);
});

test('handle returns all projects when search is empty', function () {
    Project::factory()->create(['name' => 'Alpha']);
    Project::factory()->create(['name' => 'Beta']);

    $result = app(ListProjectsAction::class)->handle('recent', '');

    expect($result['total'])->toBe(2);
});
