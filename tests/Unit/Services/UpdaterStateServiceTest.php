<?php

use App\DTOs\UpdaterState;
use App\Enums\UpdaterStatus;
use App\Services\UpdaterStateService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::forget(UpdaterStateService::CACHE_KEY);

    $this->store = new UpdaterStateService;
});

// -- transitions --

test('a check records when it started so a dev build can settle it', function () {
    config(['app.debug' => true]);

    $state = $this->store->beginCheck();

    expect($state->status)->toBe(UpdaterStatus::Checking)
        ->and($state->startedAt)->toBeInt()
        ->and($state->simulateTerminalState)->toBeTrue();
});

test('a production check is not marked for simulated settling', function () {
    config(['app.debug' => false]);

    expect($this->store->beginCheck()->simulateTerminalState)->toBeFalse();
});

test('an available update starts a download at zero percent', function () {
    $state = $this->store->recordAvailable('1.2.0', '<p>Bug fixes</p>');

    expect($state->status)->toBe(UpdaterStatus::Downloading)
        ->and($state->version)->toBe('1.2.0')
        ->and($state->releaseNotes)->toBe('Bug fixes')
        ->and($state->percent)->toBe(0);
});

test('progress keeps the version and notes of the download it belongs to', function () {
    $this->store->recordAvailable('1.2.0', 'Bug fixes');

    $state = $this->store->recordProgress(42.4);

    expect($state->status)->toBe(UpdaterStatus::Downloading)
        ->and($state->version)->toBe('1.2.0')
        ->and($state->releaseNotes)->toBe('Bug fixes')
        ->and($state->percent)->toBe(42);
});

test('progress does not knock a downloaded update back to downloading', function () {
    // A late DownloadProgress must not hide the restart affordance.
    $this->store->recordDownloaded('1.2.0', 'Bug fixes');

    $state = $this->store->recordProgress(80);

    expect($state->status)->toBe(UpdaterStatus::Ready)
        ->and($state->percent)->toBe(100)
        ->and($this->store->current()->status)->toBe(UpdaterStatus::Ready);
});

test('progress with no prior state starts a download', function () {
    expect($this->store->recordProgress(10)->status)->toBe(UpdaterStatus::Downloading);
});

test('a downloaded update is ready at one hundred percent', function () {
    $state = $this->store->recordDownloaded('1.2.0', ['First note', 'Second note']);

    expect($state->status)->toBe(UpdaterStatus::Ready)
        ->and($state->releaseNotes)->toBe('First note Second note')
        ->and($state->percent)->toBe(100);
});

test('clearing removes the state entirely', function () {
    $this->store->recordUpToDate();

    $this->store->clear();

    expect($this->store->current())->toBeNull()
        ->and(Cache::get(UpdaterStateService::CACHE_KEY))->toBeNull();
});

// -- ttls --

test('each status carries its own lifetime', function (UpdaterStatus $status, int $minSeconds, int $maxSeconds) {
    $ttl = (new ReflectionMethod(UpdaterStateService::class, 'ttlFor'))->invoke($this->store, $status);

    expect($ttl->diffInSeconds(now(), absolute: true))->toBeGreaterThanOrEqual($minSeconds)
        ->toBeLessThanOrEqual($maxSeconds);
})->with([
    'checking settles or expires fast' => [UpdaterStatus::Checking, 110, 120],
    'downloading spans a long transfer' => [UpdaterStatus::Downloading, 1790, 1800],
    'ready stays actionable for a day' => [UpdaterStatus::Ready, 86390, 86400],
    'up-to-date is a glance' => [UpdaterStatus::UpToDate, 9, 10],
    'checked-dev is a glance' => [UpdaterStatus::CheckedDev, 19, 20],
    'error clears itself' => [UpdaterStatus::Error, 290, 300],
]);

// -- resolve --

test('an update ready for the version already running is dropped', function () {
    config(['nativephp.version' => '1.2.0']);

    $this->store->recordDownloaded('1.2.0', 'Bug fixes');

    expect($this->store->resolve())->toBeNull()
        ->and(Cache::get(UpdaterStateService::CACHE_KEY))->toBeNull();
});

test('an update ready for a newer version is kept', function () {
    config(['nativephp.version' => '1.1.0']);

    $this->store->recordDownloaded('1.2.0', 'Bug fixes');

    expect($this->store->resolve()?->status)->toBe(UpdaterStatus::Ready);
});

test('a simulated dev check settles into a terminal dev state once it has had time', function () {
    config(['app.debug' => true]);

    Cache::put(UpdaterStateService::CACHE_KEY, (new UpdaterState(
        status: UpdaterStatus::Checking,
        startedAt: now()->subSeconds(3)->timestamp,
        simulateTerminalState: true,
    ))->toArray(), now()->addMinutes(2));

    expect($this->store->resolve()?->status)->toBe(UpdaterStatus::CheckedDev)
        ->and($this->store->current()?->status)->toBe(UpdaterStatus::CheckedDev);
});

test('a simulated dev check keeps spinning until it has had time', function () {
    config(['app.debug' => true]);

    $this->store->beginCheck();

    expect($this->store->resolve()?->status)->toBe(UpdaterStatus::Checking);
});

test('a real check is never settled for the user', function () {
    config(['app.debug' => false]);

    Cache::put(UpdaterStateService::CACHE_KEY, (new UpdaterState(
        status: UpdaterStatus::Checking,
        startedAt: now()->subMinutes(5)->timestamp,
        simulateTerminalState: true,
    ))->toArray(), now()->addMinutes(2));

    expect($this->store->resolve()?->status)->toBe(UpdaterStatus::Checking);
});

// -- older cached payloads --

test('a cached payload written by an older build is read back', function () {
    Cache::put(UpdaterStateService::CACHE_KEY, [
        'status' => 'downloading',
        'version' => '1.2.0',
        'releaseNotes' => 'Bug fixes',
        'percent' => 42.7,
    ], now()->addMinutes(30));

    $state = $this->store->current();

    expect($state?->status)->toBe(UpdaterStatus::Downloading)
        ->and($state->version)->toBe('1.2.0')
        ->and($state->percent)->toBe(43);
});

test('a cached payload with an unknown status is treated as no state', function () {
    Cache::put(UpdaterStateService::CACHE_KEY, ['status' => 'from-the-future'], now()->addMinutes(5));

    expect($this->store->current())->toBeNull()
        ->and($this->store->resolve())->toBeNull();
});

test('a cached payload that is not an array is treated as no state', function () {
    Cache::put(UpdaterStateService::CACHE_KEY, 'checking', now()->addMinutes(5));

    expect($this->store->current())->toBeNull();
});

// -- release notes --

test('release notes are flattened and stripped of markup', function (array|string|null $notes, ?string $expected) {
    expect(UpdaterStateService::normalizeReleaseNotes($notes))->toBe($expected);
})->with([
    'html' => ['<p>Add search bar</p>', 'Add search bar'],
    'array of releases' => [['First', 'Second'], 'First Second'],
    'null' => [null, null],
    'empty' => ['', ''],
]);
