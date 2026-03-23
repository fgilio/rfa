<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\OpenProjectFromPathAction;
use App\Listeners\HandleDeepLink;
use App\Listeners\HandleMenuItemClicked;
use Illuminate\Support\Facades\Event;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\Menu;
use Native\Desktop\Facades\Window;

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
