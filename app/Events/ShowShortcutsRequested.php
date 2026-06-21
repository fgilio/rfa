<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast to the renderer over NativePHP's `nativephp` channel when the
 * "Keyboard Shortcuts" View-menu item is clicked. The menu lives in the main
 * process while the cheat-sheet is a renderer-side Flux modal, so the click
 * crosses the bridge as a `native:` event the layout listens for and opens the
 * same modal the `?` shortcut shows.
 */
final class ShowShortcutsRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('nativephp')];
    }
}
