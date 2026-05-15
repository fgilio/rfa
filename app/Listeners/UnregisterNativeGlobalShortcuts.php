<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\NativeShortcutRegistry;
use Native\Desktop\Events\Windows\WindowBlurred;
use Native\Desktop\Facades\GlobalShortcut;

final readonly class UnregisterNativeGlobalShortcuts
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
        collect(NativeShortcutRegistry::keys())->each(function (string $key): void {
            GlobalShortcut::key($key)->unregister();
        });
    }
}
