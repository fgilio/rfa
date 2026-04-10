<?php

use App\Actions\ListProjectsAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

// -- rankMatch --

test('rankMatch returns 0 for exact name match', function () {
    expect(ListProjectsAction::rankMatch('rfa', 'main', '/tmp/rfa', 'rfa'))->toBe(0);
});

test('rankMatch returns 1 for name prefix', function () {
    expect(ListProjectsAction::rankMatch('rfa-app', 'main', '/tmp/rfa', 'rfa'))->toBe(1);
});

test('rankMatch returns 2 for name word-start', function () {
    expect(ListProjectsAction::rankMatch('my-rfa-tool', 'main', '/tmp/x', 'rfa'))->toBe(2);
});

test('rankMatch returns 3 for branch exact or prefix', function () {
    expect(ListProjectsAction::rankMatch('other', 'rfa', '/tmp/x', 'rfa'))->toBe(3);
    expect(ListProjectsAction::rankMatch('other', 'rfa-branch', '/tmp/x', 'rfa'))->toBe(3);
});

test('rankMatch returns 4 for branch word-start', function () {
    expect(ListProjectsAction::rankMatch('other', 'fix-rfa-bug', '/tmp/x', 'rfa'))->toBe(4);
});

test('rankMatch returns 5 for path word-start', function () {
    expect(ListProjectsAction::rankMatch('other', 'main', '/tmp/rfa-proj', 'rfa'))->toBe(5);
});

test('rankMatch returns 6 for name substring', function () {
    expect(ListProjectsAction::rankMatch('farfalla', 'main', '/tmp/x', 'rfa'))->toBe(6);
});

test('rankMatch returns 7 for branch substring', function () {
    expect(ListProjectsAction::rankMatch('other', 'xrfay', '/tmp/x', 'rfa'))->toBe(7);
});

test('rankMatch returns 8 for path substring', function () {
    expect(ListProjectsAction::rankMatch('other', 'main', '/tmp/xrfay', 'rfa'))->toBe(8);
});

test('rankMatch returns PHP_INT_MAX for no match', function () {
    expect(ListProjectsAction::rankMatch('other', 'main', '/tmp/x', 'zzz'))->toBe(PHP_INT_MAX);
});

test('rankMatch is case insensitive', function () {
    expect(ListProjectsAction::rankMatch('RFA', 'main', '/tmp/x', 'rfa'))->toBe(0);
    expect(ListProjectsAction::rankMatch('rfa', 'main', '/tmp/x', 'RFA'))->toBe(0);
});

// -- handle with search --

test('handle filters projects by search', function () {
    Project::create(['slug' => 'rfa', 'name' => 'rfa', 'path' => '/tmp/rfa', 'git_common_dir' => '/tmp/rfa/.git', 'is_worktree' => false, 'branch' => 'main']);
    Project::create(['slug' => 'unrelated', 'name' => 'unrelated', 'path' => '/tmp/unrelated', 'git_common_dir' => '/tmp/unrelated/.git', 'is_worktree' => false, 'branch' => 'main']);

    $result = app(ListProjectsAction::class)->handle('recent', 'rfa');
    $names = collect($result['groups'])->flatten(1)->pluck('name')->all();

    expect($names)->toBe(['rfa'])
        ->and($result['total'])->toBe(2);
});

test('handle ranks exact match above substring', function () {
    Project::create(['slug' => 'farfalla', 'name' => 'farfalla', 'path' => '/tmp/farfalla', 'git_common_dir' => '/tmp/farfalla/.git', 'is_worktree' => false, 'branch' => 'main']);
    Project::create(['slug' => 'rfa', 'name' => 'rfa', 'path' => '/tmp/rfa', 'git_common_dir' => '/tmp/rfa/.git', 'is_worktree' => false, 'branch' => 'main']);

    $result = app(ListProjectsAction::class)->handle('recent', 'rfa');
    $names = collect($result['groups'])->flatten(1)->pluck('name')->all();

    expect($names)->toBe(['rfa', 'farfalla']);
});

test('handle returns all projects when search is empty', function () {
    Project::create(['slug' => 'a', 'name' => 'Alpha', 'path' => '/tmp/a', 'git_common_dir' => '/tmp/a/.git', 'is_worktree' => false, 'branch' => 'main']);
    Project::create(['slug' => 'b', 'name' => 'Beta', 'path' => '/tmp/b', 'git_common_dir' => '/tmp/b/.git', 'is_worktree' => false, 'branch' => 'main']);

    $result = app(ListProjectsAction::class)->handle('recent', '');

    expect($result['total'])->toBe(2);
});
