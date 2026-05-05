<?php

use App\Actions\QuitAppAction;
use App\Actions\ZoomWindowAction;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    /**
     * Mouse-driven menu clicks reach the renderer through NativePHP's preload
     * bridge, so they stay in the component that owns renderer-broadcast
     * menu events instead of round-tripping through a PHP-side listener.
     *
     * Quit-rfa shares this seam (rather than its own SFC) because mounting a
     * second per-page Livewire component in the layout adds ~170ms to every
     * review-page render — the benchmark gate failed at +130%.
     */
    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    public function handleMenuClick(array $item, ZoomWindowAction $action): void
    {
        $id = $item['id'] ?? null;

        if ($id === 'quit-rfa') {
            $this->dispatch('quit-prompt-show');
            $this->skipRender();

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
     * Confirmed quit. The overlay's Alpine state machine dispatches a
     * `quit-now` Livewire event after the hold threshold has been met
     * *and* the user released Cmd or Q — Chrome's "wait for keyup"
     * pattern, since quitting while the keystroke is still down lets
     * macOS chain-quit the next foreground app.
     */
    #[On('quit-now')]
    public function quit(QuitAppAction $action): void
    {
        $action->handle();
        $this->skipRender();
    }
};

?>

<div wire:poll.1800s.keep-alive class="hidden"></div>
