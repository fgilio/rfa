<?php

use App\Actions\QuitAppAction;
use App\Actions\ZoomWindowAction;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component
{
    /**
     * Mouse-driven menu clicks reach the renderer through NativePHP's preload
     * bridge, so they stay in the component that owns renderer-broadcast
     * menu events instead of round-tripping through a PHP-side listener.
     */
    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    #[Renderless]
    public function handleMenuClick(array $item, ZoomWindowAction $action): void
    {
        $id = $item['id'] ?? null;

        if ($id === 'quit-rfa') {
            $this->dispatch('quit-prompt-show');

            return;
        }

        $direction = match ($id) {
            'zoom-in' => 'in',
            'zoom-out' => 'out',
            'reset-zoom' => 'reset',
            default => null,
        };

        if ($direction !== null) {
            $action->handle($direction);
        }
    }

    /**
     * Quits after the client-side hold threshold has completed.
     */
    #[On('quit-now')]
    #[Renderless]
    public function quit(QuitAppAction $action): void
    {
        $action->handle();
    }
};

?>

<div wire:poll.1800s.keep-alive class="hidden"></div>
