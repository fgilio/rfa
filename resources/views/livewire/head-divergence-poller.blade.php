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

    /** Branch + detached + targetExists. Changes here flip a banner. */
    #[Locked]
    public string $identity = '';

    /** Empty until first successful prime; doubles as the "primed" flag. */
    #[Locked]
    public string $sha = '';

    public function mount(): void
    {
        // Prime so the first poll after mount does not dispatch unless HEAD
        // actually moves. The parent's own mount() already ran
        // checkHeadDivergence once; we don't need to force a second round-trip.
        $head = app(GetCurrentHeadAction::class)->handle($this->repoPath, $this->target !== '' ? $this->target : null);

        if ($head->sha !== '') {
            $this->identity = $this->identityOf($head);
            $this->sha = $head->sha;
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

        $nextIdentity = $this->identityOf($head);
        $identityChanged = $nextIdentity !== $this->identity;
        $shaChanged = $head->sha !== $this->sha;

        if (! $identityChanged && ! $shaChanged) {
            return;
        }

        $primed = $this->sha !== '';
        $this->identity = $nextIdentity;
        $this->sha = $head->sha;

        // A primed identity transition (branch switch, detach, target gone)
        // is a banner-only concern — recompute divergence without re-reading
        // the file list. Everything else (sha-only advance OR a post-recovery
        // first prime where we don't know what mount-time HEAD was) leaves
        // the file list potentially stale; route it through softRefresh,
        // which re-reads files AND recomputes divergence.
        if ($primed && $identityChanged) {
            $this->dispatch('head-divergence-transitioned');

            return;
        }

        $this->dispatch('head-advanced-on-branch');
    }

    private function identityOf(CurrentHeadResult $head): string
    {
        $exists = match ($head->targetExists) {
            true => '1',
            false => '0',
            null => 'n',
        };

        return ($head->branch ?? '').'|'.($head->detached ? '1' : '0').'|'.$exists;
    }
};
?>

<div wire:smart-poll="poll" data-focus="10s" data-blur="5m" class="hidden"></div>
