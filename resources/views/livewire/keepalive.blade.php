<?php

use App\Actions\ZoomWindowAction;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Renderer keymap entry point. The View-menu zoom items send their
     * keystrokes here because Electron 38's role-based accelerators don't
     * fire `webContentsMethod` for ⌘- on macOS — see ZoomWindowAction.
     */
    #[On('rfa-zoom')]
    public function zoom(string $direction, ZoomWindowAction $action): void
    {
        $action->handle($direction);
    }

    /**
     * Mouse-driven menu clicks reach the renderer through NativePHP's preload
     * bridge, so we can route them through the same Livewire path the keymap
     * uses without round-tripping to a PHP-side `MenuItemClicked` listener.
     */
    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    public function handleMenuClick(array $item, ZoomWindowAction $action): void
    {
        $direction = match ($item['id'] ?? null) {
            'zoom-in' => 'in',
            'zoom-out' => 'out',
            'reset-zoom' => 'reset',
            default => null,
        };

        if ($direction !== null) {
            $action->handle($direction);
        }
    }
};

?>

<div wire:poll.1800s.keep-alive class="hidden"></div>
