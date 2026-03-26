<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Native\Desktop\Facades\AutoUpdater;

uses(Tests\TestCase::class);

beforeEach(function () {
    Cache::forget('native-update-state');
});

test('shows nothing when cache is empty', function () {
    Livewire::test('update-banner')
        ->assertSet('status', null)
        ->assertDontSee('Checking for updates')
        ->assertDontSee('Downloading')
        ->assertDontSee('ready')
        ->assertDontSee('up to date')
        ->assertDontSee('Update check failed');
});

test('shows checking state', function () {
    Cache::put('native-update-state', ['status' => 'checking'], now()->addMinutes(2));

    Livewire::test('update-banner')
        ->assertSet('status', 'checking')
        ->assertSee('Checking for updates...');
});

test('shows downloading state with progress', function () {
    Cache::put('native-update-state', [
        'status' => 'downloading',
        'version' => '1.2.0',
        'releaseNotes' => 'Bug fixes',
        'percent' => 42,
    ], now()->addMinutes(30));

    Livewire::test('update-banner')
        ->assertSet('status', 'downloading')
        ->assertSet('version', '1.2.0')
        ->assertSet('downloadPercent', 42)
        ->assertSee('Downloading v1.2.0...')
        ->assertSee('42%');
});

test('shows ready state with version and restart button', function () {
    Cache::put('native-update-state', [
        'status' => 'ready',
        'version' => '1.2.0',
        'releaseNotes' => 'Bug fixes and improvements',
        'percent' => 100,
    ], now()->addHours(24));

    Livewire::test('update-banner')
        ->assertSet('status', 'ready')
        ->assertSee('v1.2.0 ready')
        ->assertSee('Bug fixes and improvements')
        ->assertSee('Restart to update');
});

test('shows up-to-date state', function () {
    Cache::put('native-update-state', ['status' => 'up-to-date'], now()->addSeconds(10));

    Livewire::test('update-banner')
        ->assertSet('status', 'up-to-date')
        ->assertSeeHtml("You're up to date");
});

test('shows dev checked state after a simulated dev check settles', function () {
    config(['app.debug' => true]);

    Cache::put('native-update-state', [
        'status' => 'checking',
        'startedAt' => now()->subSeconds(3)->timestamp,
        'simulateTerminalState' => true,
    ], now()->addMinutes(2));

    Livewire::test('update-banner')
        ->assertSet('status', 'checked-dev')
        ->assertSee('Checked for updates')
        ->assertSee('Dev build - NativePHP updater does not complete here.');
});

test('shows error state', function () {
    Cache::put('native-update-state', ['status' => 'error'], now()->addMinutes(5));

    Livewire::test('update-banner')
        ->assertSet('status', 'error')
        ->assertSee('Update check failed');
});

test('resets state when cache is cleared', function () {
    Cache::put('native-update-state', [
        'status' => 'downloading',
        'version' => '1.2.0',
        'percent' => 50,
    ]);

    $component = Livewire::test('update-banner')
        ->assertSet('status', 'downloading');

    Cache::forget('native-update-state');

    $component->call('refreshState')
        ->assertSet('status', null)
        ->assertSet('version', null)
        ->assertSet('releaseNotes', null)
        ->assertSet('downloadPercent', 0);
});

test('dismiss clears cache and resets status', function () {
    Cache::put('native-update-state', [
        'status' => 'ready',
        'version' => '1.2.0',
        'percent' => 100,
    ]);

    Livewire::test('update-banner')
        ->assertSet('status', 'ready')
        ->call('dismiss')
        ->assertSet('status', null);

    expect(Cache::get('native-update-state'))->toBeNull();
});

test('restartAndUpdate calls quitAndInstall', function () {
    Cache::put('native-update-state', [
        'status' => 'ready',
        'version' => '1.2.0',
        'percent' => 100,
    ]);

    AutoUpdater::shouldReceive('quitAndInstall')->once();

    Livewire::test('update-banner')
        ->call('restartAndUpdate');
});

test('restartAndUpdate sets error state on failure', function () {
    Cache::put('native-update-state', [
        'status' => 'ready',
        'version' => '1.2.0',
        'percent' => 100,
    ]);

    AutoUpdater::shouldReceive('quitAndInstall')->once()->andThrow(new RuntimeException('Connection failed'));

    Livewire::test('update-banner')
        ->call('restartAndUpdate')
        ->assertSet('status', 'error');

    expect(Cache::get('native-update-state')['status'])->toBe('error');
});

test('refreshState updates component from cache changes', function () {
    Cache::put('native-update-state', ['status' => 'checking']);

    $component = Livewire::test('update-banner')
        ->assertSet('status', 'checking');

    Cache::put('native-update-state', [
        'status' => 'downloading',
        'version' => '1.2.0',
        'percent' => 75,
    ]);

    $component->call('refreshState')
        ->assertSet('status', 'downloading')
        ->assertSet('version', '1.2.0')
        ->assertSet('downloadPercent', 75);
});

test('native menu click shows checking state immediately', function () {
    Livewire::test('update-banner')
        ->dispatch('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked', item: ['id' => 'check-updates'])
        ->assertSet('status', 'checking')
        ->assertSee('Checking for updates...');

    expect(Cache::get('native-update-state')['status'])->toBe('checking');
});

test('native updater events update banner state immediately', function () {
    Livewire::test('update-banner')
        ->dispatch('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable', version: '1.2.0', releaseNotes: 'Bug fixes')
        ->assertSet('status', 'downloading')
        ->assertSet('version', '1.2.0')
        ->assertSee('Downloading v1.2.0...');
});

test('does not render a custom renderer message bridge', function () {
    Livewire::test('update-banner')
        ->assertDontSeeHtml('window.__rfaUpdateBannerNativeHandler')
        ->assertDontSeeHtml("window.addEventListener('message'");
});

test('uses fast polling during download', function () {
    Cache::put('native-update-state', [
        'status' => 'downloading',
        'version' => '1.2.0',
        'percent' => 50,
    ]);

    Livewire::test('update-banner')
        ->assertSeeHtml('wire:poll.2s');
});

test('uses fast polling during checking', function () {
    Cache::put('native-update-state', ['status' => 'checking'], now()->addSeconds(15));

    Livewire::test('update-banner')
        ->assertSeeHtml('wire:poll.2s');
});

test('uses idle polling when no state', function () {
    Livewire::test('update-banner')
        ->assertSeeHtml('wire:poll.5s');
});

test('uses standard polling for passive states', function () {
    Cache::put('native-update-state', [
        'status' => 'ready',
        'version' => '1.2.0',
        'percent' => 100,
    ]);

    Livewire::test('update-banner')
        ->assertSeeHtml('wire:poll.30s');
});
