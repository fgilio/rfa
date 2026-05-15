<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Support\NativeShortcutRegistry;
use Native\Desktop\Events\Windows\WindowFocused;
use Native\Desktop\Facades\GlobalShortcut;

final readonly class RegisterNativeGlobalShortcuts
{
    public function handle(WindowFocused $event): void
    {
        if ($event->id !== 'main') {
            return;
        }

        collect(NativeShortcutRegistry::all())->each(function (array $shortcut): void {
            GlobalShortcut::key($shortcut['key'])
                ->event($shortcut['event'])
                ->register();
        });
    }
}
