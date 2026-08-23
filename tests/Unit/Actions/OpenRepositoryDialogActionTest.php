<?php

use App\Actions\OpenRepositoryDialogAction;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Native\Desktop\Dialog;
use Native\Desktop\Facades\Alert;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

/** Stand in for the folder picker so the action runs without a native dialog. */
function fakeFolderPick(?string $path): void
{
    $dialog = Mockery::mock(Dialog::class);
    $dialog->shouldReceive('title', 'folders')->andReturnSelf();
    $dialog->shouldReceive('open')->andReturn($path);

    app()->instance(Dialog::class, $dialog);
}

beforeEach(function () {
    $this->repoPath = $this->createTempDirectory('rfa_open_dialog_');
    $this->initTestRepo($this->repoPath);
    File::put($this->repoPath.'/file.txt', "ok\n");
    $this->commitTestRepo($this->repoPath, 'init');

    Alert::shouldReceive('new', 'type', 'title')->andReturnSelf();
    Alert::shouldReceive('show')->andReturn(0);
});

test('returns the registered project for the picked directory', function () {
    fakeFolderPick($this->repoPath);

    expect(app(OpenRepositoryDialogAction::class)->handle())
        ->toBeInstanceOf(Project::class);
});

test('returns null when the dialog is dismissed', function () {
    fakeFolderPick(null);

    expect(app(OpenRepositoryDialogAction::class)->handle())->toBeNull()
        ->and(Context::get('rfa.reason'))->toBeNull()
        ->and(OpenRepositoryDialogAction::outcomeForNullProject())->toBe('cancelled');
});

test('marks a picked non-repository as rejected', function () {
    fakeFolderPick($this->createTempDirectory('rfa_open_dialog_nongit_'));

    expect(app(OpenRepositoryDialogAction::class)->handle())->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('not_a_git_repository')
        ->and(OpenRepositoryDialogAction::outcomeForNullProject())->toBe('rejected');
});

test('marks an unexpected registration failure as an error for the owner', function () {
    fakeFolderPick($this->repoPath);
    Schema::drop('projects');
    Log::spy();

    expect(app(OpenRepositoryDialogAction::class)->handle())->toBeNull()
        ->and(Context::get('rfa.reason'))->toBe('project_registration_failed')
        ->and(Context::get('rfa.error_class'))->not->toBeNull()
        ->and(OpenRepositoryDialogAction::outcomeForNullProject())->toBe('error');

    Log::shouldNotHaveReceived('warning');
});
