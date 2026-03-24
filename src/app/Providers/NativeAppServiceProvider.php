<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\OpenProjectFromPathAction;
use App\Listeners\HandleDeepLink;
use App\Listeners\HandleMenuItemClicked;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\DownloadProgress;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;
use Native\Desktop\Notification;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        $this->createMenu();
        $this->createWindow();
        $this->processInbox();

        Event::listen(MenuItemClicked::class, HandleMenuItemClicked::class);
        Event::listen(OpenedFromURL::class, HandleDeepLink::class);
        Event::listen(WindowClosed::class, function (WindowClosed $event) {
            if ($event->id === 'main') {
                App::quit();
            }
        });

        Event::listen(CheckingForUpdate::class, function () {
            Cache::put('native-update-state', ['status' => 'checking'], now()->addMinutes(2));
        });

        Event::listen(UpdateAvailable::class, function (UpdateAvailable $event) {
            Log::info('Update available', ['version' => $event->version]);

            $releaseNotes = is_array($event->releaseNotes) ? implode(' ', $event->releaseNotes) : $event->releaseNotes;
            Cache::put('native-update-state', [
                'status' => 'downloading',
                'version' => $event->version,
                'releaseNotes' => $releaseNotes,
                'percent' => 0,
            ]);

            Notification::new()
                ->title('Update Available')
                ->message("Version {$event->version} is available and downloading.")
                ->show();
        });

        Event::listen(DownloadProgress::class, function (DownloadProgress $event) {
            $state = Cache::get('native-update-state', []);
            $state['status'] = 'downloading';
            $state['percent'] = (int) round($event->percent);
            Cache::put('native-update-state', $state);
        });

        Event::listen(UpdateNotAvailable::class, function () {
            Cache::forget('native-update-state');
            Notification::new()
                ->title('No Updates')
                ->message('You are running the latest version.')
                ->show();
        });

        Event::listen(UpdateDownloaded::class, function (UpdateDownloaded $event) {
            Log::info('Update downloaded', ['version' => $event->version]);

            $releaseNotes = is_array($event->releaseNotes) ? implode(' ', $event->releaseNotes) : $event->releaseNotes;
            Cache::put('native-update-state', [
                'status' => 'ready',
                'version' => $event->version,
                'releaseNotes' => $releaseNotes,
                'percent' => 100,
            ]);

            Notification::new()
                ->title('Update Ready')
                ->message("Version {$event->version} will be installed on restart.")
                ->show();
        });

        Event::listen(UpdateError::class, function (UpdateError $event) {
            Log::error('Auto-update error', ['message' => $event->message, 'stack' => $event->stack]);
            Cache::put('native-update-state', ['status' => 'error'], now()->addMinutes(5));
            Notification::new()
                ->title('Update Error')
                ->message('Could not check for updates. Try again later.')
                ->show();
        });
    }

    private function createWindow(): void
    {
        Window::open('main')
            ->title('rfa')
            ->width(1280)
            ->height(860)
            ->minWidth(800)
            ->minHeight(600)
            ->backgroundColor('#0d1117')
            ->route('dashboard')
            ->rememberState();
    }

    private function createMenu(): void
    {
        Menu::create(
            Menu::app(),
            Menu::make(
                Menu::label('Open Repository...')
                    ->id('open-repo')
                    ->hotkey('CmdOrCtrl+O'),
                Menu::label('Check for Updates...')
                    ->id('check-updates'),
                Menu::separator(),
                Menu::quit(),
            )->label('File'),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
        );
    }

    private function processInbox(): void
    {
        $dir = self::inboxDir();

        if (! is_dir($dir)) {
            return;
        }

        $files = glob($dir.'/*.path');

        if ($files === false || $files === []) {
            return;
        }

        // Process the most recent entry, discard the rest
        sort($files);
        $latest = array_pop($files);

        foreach ($files as $stale) {
            @unlink($stale);
        }

        $contents = @file_get_contents($latest);
        @unlink($latest);

        if ($contents === false) {
            return;
        }

        $project = app(OpenProjectFromPathAction::class)->handle(trim($contents));

        if ($project) {
            Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
        }
    }

    public static function inboxDir(): string
    {
        $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp') : '/tmp');

        $appSupport = match (PHP_OS_FAMILY) {
            'Darwin' => $home.'/Library/Application Support',
            default => $home.'/.local/share',
        };

        return $appSupport.'/com.fgilio.rfa/inbox';
    }

    /** @return array<string, string|int> */
    public function phpIni(): array
    {
        return [
            'memory_limit' => '512M',
        ];
    }
}
