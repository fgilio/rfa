<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\OpenTerminalRequestAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveStartupRouteAction;
use App\Actions\UpdaterStateAction;
use App\Actions\ZoomWindowAction;
use App\Console\Benchmark\BenchmarkIsolation;
use App\Events\ZoomShortcutPressed;
use App\Listeners\HandleDeepLink;
use App\Listeners\HandleMenuItemClicked;
use App\Listeners\HandleZoomShortcutPressed;
use App\Listeners\RegisterNativeGlobalShortcuts;
use App\Listeners\UnregisterNativeGlobalShortcuts;
use App\Support\LogSanitizer;
use App\Support\Shortcuts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
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
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;
use Native\Desktop\Notification;
use Throwable;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    private static bool $nativeDevelopmentDatabaseChecked = false;

    private static bool $compiledViewsClearedForDev = false;

    public function boot(): void
    {
        $this->ensureNativeDevelopmentDatabaseIsMigrated();
        $this->clearCompiledViewsForDev();

        $this->registerNativeEventListeners();
        $this->createMenu();
        $this->createWindow();
        $this->processInbox();

        app(RecordRuntimeDiagnosticAction::class)->handle('app.boot', [
            'native' => (bool) config('nativephp-internal.running'),
            'debug' => (bool) config('app.debug'),
            'version' => config('nativephp.version'),
            'sapi' => PHP_SAPI,
            'entry' => basename((string) ($_SERVER['argv'][0] ?? 'unknown')),
        ]);
    }

    private function registerNativeEventListeners(): void
    {
        Event::listen(MenuItemClicked::class, HandleMenuItemClicked::class);
        Event::listen(OpenedFromURL::class, HandleDeepLink::class);
        Event::listen(WindowFocused::class, RegisterNativeGlobalShortcuts::class);
        Event::listen(WindowBlurred::class, UnregisterNativeGlobalShortcuts::class);
        Event::listen(ZoomShortcutPressed::class, HandleZoomShortcutPressed::class);
        Event::listen(WindowClosed::class, function (WindowClosed $event) {
            app(RecordRuntimeDiagnosticAction::class)->handle('window.closed', [
                'id' => $event->id,
            ]);

            if ($event->id === 'main') {
                UnregisterNativeGlobalShortcuts::unregister();
                App::quit();
            }
        });

        Event::listen(CheckingForUpdate::class, function () {
            app(UpdaterStateAction::class)->beginCheck();
        });

        Event::listen(UpdateAvailable::class, function (UpdateAvailable $event) {
            Context::flush();

            $startedAt = microtime(true);
            $outcome = 'completed';

            try {
                app(UpdaterStateAction::class)->recordAvailable($event->version, $event->releaseNotes);

                Notification::new()
                    ->title('Update Available')
                    ->message("Version {$event->version} is available and downloading.")
                    ->show();
            } catch (Throwable $e) {
                $outcome = 'error';
                Context::add('rfa.error_class', $e::class);
                Context::add('rfa.reason', 'update_available_handling_failed');

                throw $e;
            } finally {
                Context::add('rfa.update_version', $event->version);
                Context::add('rfa.outcome', $outcome);
                Context::add('rfa.duration_ms', $this->elapsedMs($startedAt));
                Log::info('updater.available');
            }
        });

        Event::listen(DownloadProgress::class, function (DownloadProgress $event) {
            app(UpdaterStateAction::class)->recordProgress($event->percent);
        });

        Event::listen(UpdateNotAvailable::class, function (UpdateNotAvailable $event) {
            Context::flush();

            $startedAt = microtime(true);
            $outcome = 'completed';

            try {
                app(UpdaterStateAction::class)->recordUpToDate();
                Notification::new()
                    ->title('No Updates')
                    ->message('You are running the latest version.')
                    ->show();
            } catch (Throwable $e) {
                $outcome = 'error';
                Context::add('rfa.error_class', $e::class);
                Context::add('rfa.reason', 'update_not_available_handling_failed');

                throw $e;
            } finally {
                Context::add('rfa.update_version', $event->version);
                Context::add('rfa.outcome', $outcome);
                Context::add('rfa.duration_ms', $this->elapsedMs($startedAt));
                Log::info('updater.current');
            }
        });

        Event::listen(UpdateDownloaded::class, function (UpdateDownloaded $event) {
            Context::flush();

            $startedAt = microtime(true);
            $outcome = 'completed';

            try {
                app(UpdaterStateAction::class)->recordDownloaded($event->version, $event->releaseNotes);

                Notification::new()
                    ->title('Update Ready')
                    ->message("Version {$event->version} will be installed on restart.")
                    ->show();
            } catch (Throwable $e) {
                $outcome = 'error';
                Context::add('rfa.error_class', $e::class);
                Context::add('rfa.reason', 'update_downloaded_handling_failed');

                throw $e;
            } finally {
                Context::add('rfa.update_version', $event->version);
                Context::add('rfa.outcome', $outcome);
                Context::add('rfa.duration_ms', $this->elapsedMs($startedAt));
                Log::info('updater.downloaded');
            }
        });

        Event::listen(UpdateError::class, function (UpdateError $event) {
            Context::flush();

            $startedAt = microtime(true);

            try {
                // Diagnostic detail for triage. The canonical info event below
                // keeps the failure path the same shape as the success paths so
                // `rfa.outcome` distribution stays queryable across outcomes.
                Log::error('updater.failed', [
                    'reason' => 'updater_error',
                    'message' => LogSanitizer::summary($event->message),
                    'stack' => LogSanitizer::summary($event->stack, 500),
                ]);
                app(UpdaterStateAction::class)->recordError();
                Notification::new()
                    ->title('Update Error')
                    ->message('Could not check for updates. Try again later.')
                    ->show();
            } finally {
                Context::add('rfa.outcome', 'error');
                Context::add('rfa.reason', 'updater_error');
                Context::add('rfa.duration_ms', $this->elapsedMs($startedAt));
                Log::info('updater.failed');
            }
        });
    }

    /**
     * Milliseconds elapsed since `$startedAt` (a {@see microtime()} float).
     *
     * The updater listeners have no reliable end-to-end operation start time —
     * the `native-update-state` cache is written by both this main process and
     * the renderer banner, so its `startedAt` can be raced. Per the wide-events
     * protocol, a listener-owned event without a trustworthy start time records
     * its callback duration instead.
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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
            ->zoomFactor(app(ZoomWindowAction::class)->current())
            ->url(app(ResolveStartupRouteAction::class)->handle())
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
                    ->hotkey(Shortcuts::accelerator('app.add-repo'))
                    ->icon(resource_path('icons/add-repoTemplate.png')),
                Menu::label('Scan Folder for Repos...')
                    ->id('scan-directory')
                    ->icon(resource_path('icons/scan-folderTemplate.png')),
            )->label('File'),
            Menu::edit(),
            // Custom View submenu that intentionally omits `reload`.
            // App-level keyboard commands live in NativeShortcutRegistry:
            //
            //   - viewMenu would bind ⌘R to Electron's built-in reload, and
            //     NativePHP's menu helper strips custom accelerators from role
            //     items so the binding cannot be retargeted.
            //   - The zoom roles' `webContentsMethod` is registered as a macOS
            //     menu accelerator on Electron 38, but the keystroke fails to
            //     fire it. Only mouse clicks do (electron/electron#19559,
            //     #15496). Explicit menu accelerators do not help.
            Menu::make(
                Menu::label('Review Code')
                    ->id('review-code')
                    ->hotkey(Shortcuts::accelerator('app.review-code')),
                Menu::label('Review Agents instructions')
                    ->id('show-context')
                    ->hotkey(Shortcuts::accelerator('app.context-files')),
                Menu::label('Keyboard Shortcuts')
                    ->id('show-shortcuts'),
                Menu::label('Toggle Sidebar')
                    ->id('toggle-sidebar'),
                Menu::separator(),
                Menu::label('Actual Size')->id('reset-zoom'),
                Menu::label('Zoom In')->id('zoom-in'),
                Menu::label('Zoom Out')->id('zoom-out'),
                Menu::separator(),
                Menu::fullscreen(),
                Menu::separator(),
                Menu::devTools(),
            )->label('View'),
            Menu::window(),
        );
    }

    private function processInbox(): void
    {
        $latest = $this->claimLatestInboxFile();

        if ($latest === null) {
            return;
        }

        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';

        try {
            $outcome = $this->openFromInboxFile($latest);
        } catch (Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'inbox_open_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('inbox.opened');
        }
    }

    /**
     * Take the newest queued request and discard the rest.
     *
     * Returns null when the terminal helper left nothing to open, which
     * is the common case on any boot the user did not start
     * from `./rfa`.
     */
    private function claimLatestInboxFile(): ?string
    {
        $dir = self::inboxDir();

        if (! File::isDirectory($dir)) {
            return null;
        }

        $files = File::glob($dir.'/*.path');

        if ($files === []) {
            return null;
        }

        sort($files);
        $latest = array_pop($files);

        foreach ($files as $stale) {
            File::delete($stale);
        }

        return $latest;
    }

    /**
     * Open the repository a claimed inbox file names, and return the outcome
     * for the canonical event.
     */
    private function openFromInboxFile(string $file): string
    {
        $contents = rescue(fn () => File::get($file));
        // The filename stem is the request id the terminal helper also put in
        // the deep link, so claiming it here is what stops the URL delivery of
        // the same request from opening the project a second time.
        $requestId = OpenTerminalRequestAction::inboxRequestId($file);
        File::delete($file);

        Context::add('rfa.request_id', $requestId);

        if ($contents === null) {
            Context::add('rfa.reason', 'unreadable_inbox_file');

            return 'rejected';
        }

        ['path' => $path, 'mode' => $mode] = OpenTerminalRequestAction::parseInboxContents($contents);

        if ($path === '') {
            Context::add('rfa.reason', 'missing_path');

            return 'rejected';
        }

        $routeName = OpenTerminalRequestAction::routeName($mode);

        Context::add('rfa.mode', $mode);
        Context::add('rfa.path_hash', hash('xxh128', $path));
        Context::add('rfa.route', $routeName);

        $project = app(OpenTerminalRequestAction::class)->handle($path, $mode, $requestId);

        if (! $project) {
            $outcome = OpenTerminalRequestAction::outcomeForNullProject();

            Context::addIf('rfa.reason', 'not_a_project');

            return $outcome;
        }

        Context::add('rfa.project_id', $project->id);
        Context::add('rfa.project_slug', $project->slug);

        app(RecordRuntimeDiagnosticAction::class)->handle('inbox.opened', [
            'route' => $routeName,
            'request_id' => $requestId,
            'project_id' => $project->id,
            'project_slug' => $project->slug,
            'path_hash' => hash('xxh128', $path),
        ]);

        return 'completed';
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
            ...$this->opcacheIniSettings(),
        ];
    }

    /**
     * Opcode caching for the bundled PHP.
     *
     * NativePHP serves the app through PHP's built-in server (CLI SAPI, where
     * opcache is off by default) and shells out to short-lived `php artisan`
     * processes during launch (e.g. `config:cache`). Without opcache each of
     * those recompiles the whole framework on every boot.
     *
     * `enable` + `enable_cli` turn it on for both. `file_cache` persists the
     * compiled opcode to disk so every short-lived process — and the first
     * request after launch — reuses opcode produced by earlier runs instead of
     * recompiling (measured ~210ms -> ~120ms per artisan boot here). The
     * directory lives under the (userData) storage path and survives across
     * launches; `validate_timestamps` keeps it correct in dev (`native:run`)
     * and across updates by recompiling only the files whose mtime changed.
     *
     * opcache must be able to write its cache directory, and it does not create
     * one itself, so ensure it exists. If the bundled PHP lacks opcache the
     * `-d opcache.*` flags are simply ignored.
     *
     * @return array<string, string|int>
     */
    private function opcacheIniSettings(): array
    {
        $cacheDir = storage_path('framework/opcache');

        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        return [
            'opcache.enable' => 1,
            'opcache.enable_cli' => 1,
            'opcache.validate_timestamps' => 1,
            'opcache.revalidate_freq' => 0,
            'opcache.memory_consumption' => 192,
            'opcache.max_accelerated_files' => 30000,
            'opcache.file_cache' => $cacheDir,
        ];
    }

    private function ensureNativeDevelopmentDatabaseIsMigrated(): void
    {
        if (! config('app.debug') || ! config('nativephp-internal.running')) {
            return;
        }

        // Defence in depth: in a packaged build NativePHP runs `migrate --force`
        // itself at launch, so this dev-only scan is redundant there. A packaged
        // build already reports APP_DEBUG=false (covered by the guard above);
        // keying off the config cache keeps the scan scoped to the un-optimized
        // dev runtime (`native:run`) regardless of how debug is configured.
        if (app()->configurationIsCached()) {
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

        // Defence in depth against ever clearing the Blade cache in a packaged
        // build. NativePHP runs `php artisan optimize` at launch (compiling
        // every view) and serves requests through PHP's built-in server, which
        // re-bootstraps Laravel per request — so clearing here would force a
        // full recompile on every navigation. A packaged build already reports
        // APP_DEBUG=false (so the guard above covers it today), but keying off
        // the config cache keeps view clearing scoped to the un-optimized dev
        // runtime (`native:run`) regardless of how debug is configured.
        if (app()->configurationIsCached()) {
            return;
        }

        if (app()->environment('testing')) {
            return;
        }

        if ((string) getenv(BenchmarkIsolation::ENV_ENABLED) === '1') {
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
