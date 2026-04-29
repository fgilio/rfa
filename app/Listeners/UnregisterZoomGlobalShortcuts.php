<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ZoomShortcutPressed;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Facades\GlobalShortcut;

final readonly class UnregisterZoomGlobalShortcuts
{
    public function handle(WindowBlurred $event): void
    {
        if ($event->id !== 'main') {
            return;
        }

        self::unregister();
    }

    public static function unregister(): void
    {
        collect(ZoomShortcutPressed::keys())->each(function (string $key): void {
            GlobalShortcut::key($key)->unregister();
        });
    }
}
