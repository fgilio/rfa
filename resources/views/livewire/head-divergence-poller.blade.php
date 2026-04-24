<?php

use App\Actions\GetCurrentHeadAction;
use App\DTOs\CurrentHeadResult;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component
{
    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public string $target = '';

    #[Locked]
    public string $fingerprint = '';

    public function mount(): void
    {
        // Prime the fingerprint so the first poll after mount does not dispatch
        // unless HEAD actually moves. The parent's own mount() already ran
        // checkHeadDivergence once; we don't need to force a second round-trip.
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->target !== '' ? $this->target : null);

        if ($head->sha !== '') {
            $this->fingerprint = $this->fingerprintOf($head);
        }
    }

    #[Renderless]
    public function poll(): void
    {
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->target !== '' ? $this->target : null);

        // Sentinel: GetCurrentHeadAction returns sha='' when git fails transiently
        // (mid-rebase, lock contention). Skip this tick so we don't mask a real
        // state with a spurious transition — retry on the next poll.
        if ($head->sha === '') {
            return;
        }

        $next = $this->fingerprintOf($head);

        if ($next === $this->fingerprint) {
            return;
        }

        $this->fingerprint = $next;
        $this->dispatch('head-divergence-transitioned');
    }

    private function fingerprintOf(CurrentHeadResult $head): string
    {
        $targetExists = match ($head->targetExists) {
            true => '1',
            false => '0',
            null => 'n',
        };

        return ($head->branch ?? '').'|'.$head->sha.'|'.($head->detached ? '1' : '0').'|'.$targetExists;
    }
};
?>

<div wire:poll.2s="poll" class="hidden"></div>
