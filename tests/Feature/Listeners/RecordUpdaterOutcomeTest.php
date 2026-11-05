<?php

use App\Listeners\RecordUpdaterOutcome;
use App\Services\UpdaterStateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, InteractsWithTestRepositories::class);

beforeEach(function () {
    $this->logDirectory = $this->createTempDirectory('rfa_updater_outcome_');

    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.path' => $this->logDirectory.'/rfa-diagnostics.jsonl',
    ]);

    Http::fake();
});

/** @return list<array<string, mixed>> */
function updaterDiagnostics(string $directory): array
{
    return collect(File::lines($directory.'/rfa-diagnostics.jsonl'))
        ->filter()
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

// -- registration --

test('handles each updater outcome through a discovered listener', function (string $event, string $method) {
    Event::fake();

    Event::assertListening($event, RecordUpdaterOutcome::class.'@'.$method);
})->with([
    'available' => [UpdateAvailable::class, 'handleUpdateAvailable'],
    'not available' => [UpdateNotAvailable::class, 'handleUpdateNotAvailable'],
    'downloaded' => [UpdateDownloaded::class, 'handleUpdateDownloaded'],
    'error' => [UpdateError::class, 'handleUpdateError'],
]);

test('logs an updater error delivered by electron at the packaged log level', function () {
    $logPath = $this->logDirectory.'/laravel.log';

    config([
        'logging.channels.packaged' => ['driver' => 'single', 'path' => $logPath, 'level' => 'warning'],
        'logging.default' => 'packaged',
    ]);

    // Electron posts every event to this endpoint in a fresh request, which
    // never runs NativeAppServiceProvider::boot().
    $this->postJson('/_native/api/events', [
        'event' => UpdateError::class,
        'payload' => [
            'name' => 'Error',
            'message' => "Cannot find latest-mac.yml in {$_SERVER['HOME']}/Library/Caches/rfa-updater\n  status 404",
            'stack' => 'Error: Cannot find latest-mac.yml',
        ],
    ])->assertOk();

    expect(File::get($logPath))
        ->toContain('updater.failed')
        ->toContain('Cannot find latest-mac.yml in ~/Library/Caches/rfa-updater status 404')
        ->not->toContain($_SERVER['HOME'].'/Library')
        ->and(collect(updaterDiagnostics($this->logDirectory))->pluck('event')->all())->toBe(['updater.failed']);
});

// -- outcomes --

test('records a canonical event for each successful updater outcome', function (Closure $makeEvent, string $method, string $eventName) {
    Log::spy();

    app(RecordUpdaterOutcome::class)->{$method}($makeEvent());

    Log::shouldHaveReceived('info')->once()->with($eventName);
    Log::shouldNotHaveReceived('error');

    expect(Context::get('rfa.update_version'))->toBe('1.2.3')
        ->and(Context::get('rfa.outcome'))->toBe('completed')
        ->and(Context::get('rfa.duration_ms'))->toBeInt()
        ->and(updaterDiagnostics($this->logDirectory))->sequence(
            fn ($entry) => $entry->toMatchArray(['event' => $eventName, 'context' => ['version' => '1.2.3']]),
        );
})->with([
    'available' => [
        fn () => new UpdateAvailable(version: '1.2.3', files: [], releaseDate: '2026-11-05'),
        'handleUpdateAvailable',
        'updater.available',
    ],
    'not available' => [
        fn () => new UpdateNotAvailable(version: '1.2.3', files: [], releaseDate: '2026-11-05'),
        'handleUpdateNotAvailable',
        'updater.not_available',
    ],
    'downloaded' => [
        fn () => new UpdateDownloaded(downloadedFile: '/tmp/rfa.zip', version: '1.2.3', files: [], releaseDate: '2026-11-05'),
        'handleUpdateDownloaded',
        'updater.downloaded',
    ],
]);

test('records a failed outcome with the sanitized error', function () {
    Log::spy();

    app(RecordUpdaterOutcome::class)->handleUpdateError(new UpdateError(
        name: 'HttpError',
        message: "\e[31mnet::ERR_INTERNET_DISCONNECTED\e[0m\n  at {$_SERVER['HOME']}/rfa",
        stack: null,
    ));

    Log::shouldHaveReceived('error')->once()->with('updater.failed', [
        'reason' => 'updater_error',
        'error_name' => 'HttpError',
        'message' => 'net::ERR_INTERNET_DISCONNECTED at ~/rfa',
        'stack' => '',
    ]);
    Log::shouldHaveReceived('info')->once()->with('updater.failed');

    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('updater_error')
        ->and(Context::get('rfa.error_name'))->toBe('HttpError')
        ->and(Context::get('rfa.duration_ms'))->toBeInt()
        ->and(updaterDiagnostics($this->logDirectory))->sequence(
            fn ($entry) => $entry->toMatchArray([
                'event' => 'updater.failed',
                'context' => ['error_name' => 'HttpError', 'message' => 'net::ERR_INTERNET_DISCONNECTED at ~/rfa'],
            ]),
        );
});

test('starts each outcome from a clean context', function () {
    Context::add('rfa.reason', 'stale_reason');

    app(RecordUpdaterOutcome::class)->handleUpdateAvailable(
        new UpdateAvailable(version: '1.2.3', files: [], releaseDate: '2026-11-05'),
    );

    expect(Context::has('rfa.reason'))->toBeFalse();
});

test('leaves updater state and notifications to the update banner', function () {
    $listener = app(RecordUpdaterOutcome::class);

    $listener->handleUpdateAvailable(new UpdateAvailable(version: '1.2.3', files: [], releaseDate: '2026-11-05'));
    $listener->handleUpdateNotAvailable(new UpdateNotAvailable(version: '1.2.3', files: [], releaseDate: '2026-11-05'));
    $listener->handleUpdateDownloaded(new UpdateDownloaded(downloadedFile: '/tmp/rfa.zip', version: '1.2.3', files: [], releaseDate: '2026-11-05'));
    $listener->handleUpdateError(new UpdateError(name: 'Error', message: 'offline'));

    expect(Cache::has(UpdaterStateService::CACHE_KEY))->toBeFalse();
    Http::assertNothingSent();
});
