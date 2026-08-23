<?php

use App\Actions\UpdaterStateAction;
use App\Services\UpdaterStateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::forget(UpdaterStateService::CACHE_KEY);

    $this->action = app(UpdaterStateAction::class);
});

test('an empty store yields an idle snapshot', function () {
    expect($this->action->handle())->toBe([
        'status' => null,
        'version' => null,
        'releaseNotes' => null,
        'downloadPercent' => 0,
    ]);
});

test('every entry point returns the same snapshot shape', function (string $method, array $arguments) {
    expect(array_keys($this->action->{$method}(...$arguments)))
        ->toBe(['status', 'version', 'releaseNotes', 'downloadPercent']);
})->with([
    'handle' => ['handle', []],
    'beginCheck' => ['beginCheck', []],
    'recordAvailable' => ['recordAvailable', ['1.2.0', 'Bug fixes']],
    'recordProgress' => ['recordProgress', [50]],
    'recordDownloaded' => ['recordDownloaded', ['1.2.0', 'Bug fixes']],
    'recordUpToDate' => ['recordUpToDate', []],
    'recordError' => ['recordError', []],
    'dismiss' => ['dismiss', []],
]);

test('a downloaded update reaches the snapshot the banner renders', function () {
    config(['nativephp.version' => '1.1.0']);

    expect($this->action->recordDownloaded('1.2.0', '<p>Bug fixes</p>'))->toBe([
        'status' => 'ready',
        'version' => '1.2.0',
        'releaseNotes' => 'Bug fixes',
        'downloadPercent' => 100,
    ]);

    expect($this->action->handle()['status'])->toBe('ready');
});

test('dismissing leaves nothing behind', function () {
    $this->action->recordDownloaded('1.2.0');

    expect($this->action->dismiss()['status'])->toBeNull()
        ->and(Cache::get(UpdaterStateService::CACHE_KEY))->toBeNull();
});
