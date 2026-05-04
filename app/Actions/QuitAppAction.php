<?php

declare(strict_types=1);

namespace App\Actions;

use Native\Desktop\Facades\App;

/**
 * Single seam for quitting the app, so the hold-to-quit overlay and any
 * future quit entry point share one path. The standard `Menu::quit()`
 * role wires Cmd+Q directly into Electron's native quit; we replace it
 * with a custom labeled item that funnels through here instead, giving
 * the renderer a chance to gate accidental presses.
 */
final readonly class QuitAppAction
{
    public function handle(): void
    {
        App::quit();
    }
}
