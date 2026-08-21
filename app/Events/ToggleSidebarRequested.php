<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast to the renderer over NativePHP's `nativephp` channel when the
 * "Toggle Sidebar" View-menu item is clicked. The menu lives in the main
 * process while sidebar visibility is renderer state (Alpine's settings
 * store), so the click crosses the bridge as a `native:` event the layout
 * turns into the same window event the hyper+S shortcut ends at.
 */
final class ToggleSidebarRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new Channel('nativephp')];
    }
}
