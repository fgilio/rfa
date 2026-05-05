<?php

declare(strict_types=1);

namespace App\Actions;

use Native\Desktop\Facades\App;

/**
 * Quits the desktop app through one shared action.
 *
 * Native `Menu::quit()` bypasses PHP and the renderer. The custom menu
 * item routes confirmation through Livewire before calling NativePHP.
 */
final readonly class QuitAppAction
{
    public function handle(): void
    {
        App::quit();
    }
}
