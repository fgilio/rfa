<?php

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Alert;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/** Stand in for the folder picker both menu actions open. */
function fakePickedFolder(?string $path): void
{
    $dialog = Mockery::mock(Dialog::class);
    $dialog->shouldReceive('title', 'folders')->andReturnSelf();
    $dialog->shouldReceive('open')->andReturn($path);

    app()->instance(Dialog::class, $dialog);
}

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_add_menu_');
    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/file.txt', "ok\n");
    $this->commitTestRepo($this->repoPath, 'init');

    Alert::shouldReceive('new', 'type', 'title')->andReturnSelf();
    Alert::shouldReceive('show')->andReturn(0);

    Livewire::withoutLazyLoading();
});

// -- project.opened --

test('emits a canonical project.opened event for a picked repository', function () {
    fakePickedFolder($this->repoPath);
    Log::spy();

    Livewire::test('add-project-menu')->call('openRepository');

    Log::shouldHaveReceived('info')->once()->with('project.opened');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.project_slug'))->not->toBeNull()
        ->and(Context::get('rfa.project_id'))->toBeInt()
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('emits a cancelled outcome when the picker is dismissed', function () {
    fakePickedFolder(null);
    Log::spy();

    Livewire::test('add-project-menu')->call('openRepository');

    Log::shouldHaveReceived('info')->once()->with('project.opened');
    expect(Context::get('rfa.outcome'))->toBe('cancelled');
});

test('emits a rejected outcome when the picked folder is not a repository', function () {
    fakePickedFolder($this->createTempDirectory('rfa_add_menu_nongit_'));
    Log::spy();

    Livewire::test('add-project-menu')->call('openRepository');

    Log::shouldHaveReceived('info')->once()->with('project.opened');
    expect(Context::get('rfa.outcome'))->toBe('rejected')
        ->and(Context::get('rfa.reason'))->toBe('not_a_git_repository');
});

// -- directory.scanned --

test('emits a canonical directory.scanned event with the scan counts', function () {
    $parent = $this->createTempDirectory('rfa_add_menu_scan_');
    $nested = $parent.'/repo';
    File::makeDirectory($nested);
    $this->initTestRepo($nested);
    File::put($nested.'/file.txt', "ok\n");
    $this->commitTestRepo($nested, 'init');

    fakePickedFolder($parent);
    Log::spy();

    Livewire::test('add-project-menu')->call('scanDirectory');

    Log::shouldHaveReceived('info')->once()->with('directory.scanned');
    expect(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.repos_found'))->toBe(1)
        ->and(Context::get('rfa.repos_registered'))->toBe(1)
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
});

test('emits a cancelled outcome when the scan picker is dismissed', function () {
    fakePickedFolder(null);
    Log::spy();

    Livewire::test('add-project-menu')->call('scanDirectory');

    Log::shouldHaveReceived('info')->once()->with('directory.scanned');
    expect(Context::get('rfa.outcome'))->toBe('cancelled');
});
