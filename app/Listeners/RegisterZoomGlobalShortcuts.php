<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ZoomShortcutPressed;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Facades\GlobalShortcut;

final readonly class RegisterZoomGlobalShortcuts
{
    public function handle(WindowFocused $event): void
    {
        if ($event->id !== 'main') {
            return;
        }

        collect(ZoomShortcutPressed::keys())->each(function (string $key): void {
            GlobalShortcut::key($key)
                ->event(ZoomShortcutPressed::class)
                ->register();
        });
    }
}
