<?php

use App\Actions\ZoomWindowAction;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Mouse-driven menu clicks reach the renderer through NativePHP's preload
     * bridge, so they stay in the component that owns renderer-broadcast
     * menu events instead of round-tripping through a PHP-side listener.
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
