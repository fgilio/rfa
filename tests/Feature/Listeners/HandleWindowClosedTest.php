<?php

use App\Listeners\HandleWindowClosed;
use App\Support\NativeShortcutRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\GlobalShortcut;
use Tests\Helpers\InteractsWithTestRepositories;
use Tests\TestCase;

uses(TestCase::class, InteractsWithTestRepositories::class);

beforeEach(function () {
    $this->diagnosticsPath = $this->createTempDirectory('rfa_window_closed_').'/rfa-diagnostics.jsonl';

    config([
        'rfa.diagnostics.enabled' => true,
        'rfa.diagnostics.path' => $this->diagnosticsPath,
    ]);

    Http::fake();
    $this->shortcuts = GlobalShortcut::fake();
});

/** @return list<array<string, mixed>> */
function windowClosedDiagnostics(string $path): array
{
    return collect(File::lines($path))
        ->filter()
        ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
        ->values()
        ->all();
}

function assertQuitRequested(int $times): void
{
    Http::assertSentCount($times);

    if ($times > 0) {
        Http::assertSent(fn (Request $request): bool => Str::endsWith($request->url(), 'app/quit'));
    }
}

test('handles window close through a discovered listener', function () {
    Event::fake();

    Event::assertListening(WindowClosed::class, HandleWindowClosed::class);
});

test('quits when electron reports the main window closed', function () {
    // Electron posts every event to this endpoint in a fresh request, which
    // never runs NativeAppServiceProvider::boot().
    $this->postJson('/_native/api/events', [
        'event' => WindowClosed::class,
        'payload' => ['main'],
    ])->assertOk();

    assertQuitRequested(1);
    $this->shortcuts->assertUnregisteredCount(count(NativeShortcutRegistry::keys()));

    expect(windowClosedDiagnostics($this->diagnosticsPath))->sequence(
        fn ($entry) => $entry->toMatchArray(['event' => 'window.closed', 'context' => ['id' => 'main']]),
    );
});

test('keeps running when another window closes', function () {
    $this->postJson('/_native/api/events', [
        'event' => WindowClosed::class,
        'payload' => ['settings'],
    ])->assertOk();

    assertQuitRequested(0);
    $this->shortcuts->assertUnregisteredCount(0);

    expect(windowClosedDiagnostics($this->diagnosticsPath))->sequence(
        fn ($entry) => $entry->toMatchArray(['event' => 'window.closed', 'context' => ['id' => 'settings']]),
    );
});

test('records a canonical event for each window close', function (string $windowId, bool $quits, string $outcome) {
    Log::spy();

    app(HandleWindowClosed::class)->handle(new WindowClosed($windowId));

    Log::shouldHaveReceived('info')->once()->with('window.closed');

    expect(Context::get('rfa.window_id'))->toBe($windowId)
        ->and(Context::get('rfa.quit_requested'))->toBe($quits)
        ->and(Context::get('rfa.outcome'))->toBe($outcome)
        ->and(Context::get('rfa.duration_ms'))->toBeInt();
})->with([
    'main' => ['main', true, 'completed'],
    'other' => ['settings', false, 'skipped'],
]);

test('records an error outcome when the quit request fails', function () {
    Log::spy();
    Http::fake(fn () => throw new RuntimeException('Electron is gone.'));

    expect(fn () => app(HandleWindowClosed::class)->handle(new WindowClosed('main')))
        ->toThrow(RuntimeException::class, 'Electron is gone.');

    Log::shouldHaveReceived('info')->once()->with('window.closed');

    expect(Context::get('rfa.outcome'))->toBe('error')
        ->and(Context::get('rfa.reason'))->toBe('window_close_failed')
        ->and(Context::get('rfa.error_class'))->toBe(RuntimeException::class);
});
