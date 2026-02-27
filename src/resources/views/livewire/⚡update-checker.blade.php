<?php

use App\Actions\CheckForAppUpdateAction;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public bool $notified = false;

    public function checkForUpdate(): void
    {
        if ($this->notified) {
            return;
        }

        $result = app(CheckForAppUpdateAction::class)->handle();

        if ($result['available']) {
            $this->notified = true;
            $s = $result['count'] === 1 ? '' : 's';

            Flux::toast(
                variant: 'warning',
                heading: 'Update available',
                text: "{$result['count']} new commit{$s}. Run git pull in the rfa directory to update.",
                duration: 10000,
            );
        }
    }
};

?>

<div wire:init="checkForUpdate" wire:poll.3600s="checkForUpdate"></div>
