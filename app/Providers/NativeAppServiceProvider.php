<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\OpenProjectFromPathAction;
use App\Actions\ResolveStartupRouteAction;
use App\Listeners\HandleDeepLink;
use App\Listeners\HandleMenuItemClicked;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
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
    private static bool $nativeDevelopmentDatabaseChecked = false;

    private static bool $compiledViewsClearedForDev = false;

    public function boot(): void
    {
        $this->ensureNativeDevelopmentDatabaseIsMigrated();
        $this->clearCompiledViewsForDev();

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
            Cache::put('native-update-state', [
                'status' => 'checking',
                'startedAt' => now()->timestamp,
                'simulateTerminalState' => config('app.debug'),
            ], now()->addMinutes(2));
        });

        Event::listen(UpdateAvailable::class, function (UpdateAvailable $event) {
            Log::info('Update available', ['version' => $event->version]);

            $releaseNotes = $this->normalizeReleaseNotes($event->releaseNotes);
            Cache::put('native-update-state', [
                'status' => 'downloading',
                'version' => $event->version,
                'releaseNotes' => $releaseNotes,
                'percent' => 0,
            ], now()->addMinutes(30));

            Notification::new()
                ->title('Update Available')
                ->message("Version {$event->version} is available and downloading.")
                ->show();
        });

        Event::listen(DownloadProgress::class, function (DownloadProgress $event) {
            $state = Cache::get('native-update-state', []);
            $state['status'] = 'downloading';
            $state['percent'] = (int) round($event->percent);
            Cache::put('native-update-state', $state, now()->addMinutes(30));
        });

        Event::listen(UpdateNotAvailable::class, function () {
            Cache::put('native-update-state', ['status' => 'up-to-date'], now()->addSeconds(10));
            Notification::new()
                ->title('No Updates')
                ->message('You are running the latest version.')
                ->show();
        });

        Event::listen(UpdateDownloaded::class, function (UpdateDownloaded $event) {
            Log::info('Update downloaded', ['version' => $event->version]);

            $releaseNotes = $this->normalizeReleaseNotes($event->releaseNotes);
            Cache::put('native-update-state', [
                'status' => 'ready',
                'version' => $event->version,
                'releaseNotes' => $releaseNotes,
                'percent' => 100,
            ], now()->addHours(24));

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

    /** @param array<string>|string|null $notes */
    private function normalizeReleaseNotes(array|string|null $notes): ?string
    {
        return is_array($notes) ? implode(' ', $notes) : $notes;
    }

    private function createWindow(): void
    {
        $route = app(ResolveStartupRouteAction::class)->handle();

        Window::open('main')
            ->title('rfa')
            ->width(1280)
            ->height(860)
            ->minWidth(800)
            ->minHeight(600)
            ->backgroundColor('#0d1117')
            ->route($route['name'], $route['params'])
            ->rememberState();
    }

    private function createMenu(): void
    {
        Menu::create(
            Menu::make(
                Menu::about(),
                Menu::separator(),
                Menu::label('Check for Updates...')
                    ->id('check-updates')
                    ->icon(resource_path('icons/check-updatesTemplate.png')),
                Menu::link(config('nativephp.website').'/releases', 'See Releases...')
                    ->openInBrowser()
                    ->icon(resource_path('icons/see-releasesTemplate.png')),
                Menu::separator(),
                Menu::hide(),
                Menu::separator(),
                Menu::quit(),
            )->label(config('app.name')),
            Menu::make(
                Menu::label('Add Single Repository...')
                    ->id('open-repo')
                    ->hotkey('CmdOrCtrl+O')
                    ->icon(resource_path('icons/add-repoTemplate.png')),
                Menu::label('Scan Folder for Repos...')
                    ->id('scan-directory')
                    ->icon(resource_path('icons/scan-folderTemplate.png')),
            )->label('File'),
            Menu::edit(),
            Menu::view(),
            Menu::window(),
        );
    }

    private function processInbox(): void
    {
        $dir = self::inboxDir();

        if (! File::isDirectory($dir)) {
            return;
        }

        $files = File::glob($dir.'/*.path');

        if ($files === []) {
            return;
        }

        // Process the most recent entry, discard the rest
        sort($files);
        $latest = array_pop($files);

        foreach ($files as $stale) {
            File::delete($stale);
        }

        $contents = rescue(fn () => File::get($latest));
        File::delete($latest);

        if ($contents === null) {
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

    private function ensureNativeDevelopmentDatabaseIsMigrated(): void
    {
        if (! config('app.debug') || ! config('nativephp-internal.running')) {
            return;
        }

        if (self::$nativeDevelopmentDatabaseChecked) {
            return;
        }

        self::$nativeDevelopmentDatabaseChecked = true;

        $migrator = app('migrator');
        $repository = app('migration.repository');

        $hasPendingMigrations = $migrator->usingConnection(config('database.default'), function () use ($migrator, $repository): bool {
            if (! $repository->repositoryExists()) {
                return true;
            }

            $migrationFiles = $migrator->getMigrationFiles([
                database_path('migrations'),
                ...$migrator->paths(),
            ]);

            return collect(array_keys($migrationFiles))
                ->diff($repository->getRan())
                ->isNotEmpty();
        });

        if ($hasPendingMigrations) {
            Artisan::call('native:migrate', ['--force' => true]);
        }
    }

    private function clearCompiledViewsForDev(): void
    {
        if (! config('app.debug')) {
            return;
        }

        if (self::$compiledViewsClearedForDev) {
            return;
        }

        collect([
            storage_path('framework/views'),
            base_path('storage/framework/views'),
        ])
            ->unique()
            ->filter(fn (string $path) => File::isDirectory($path))
            ->each(function (string $path): void {
                collect(File::glob($path.'/*.php') ?: [])
                    ->each(fn (string $file) => File::delete($file));

                $livewireViewsPath = $path.'/livewire';

                if (File::isDirectory($livewireViewsPath)) {
                    File::deleteDirectory($livewireViewsPath);
                }
            });

        self::$compiledViewsClearedForDev = true;
    }
}
