<?php

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\ScanDirectoryDialogAction;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public string $variant = 'dropdown';

    public function openRepository(): void
    {
        $project = app(OpenRepositoryDialogAction::class)->handle();

        if (! $project) {
            return;
        }

        $this->redirect(route('review-page', ['slug' => $project->slug]), navigate: true);
    }

    public function scanDirectory(): void
    {
        try {
            $result = app(ScanDirectoryDialogAction::class)->handle();
        } catch (\InvalidArgumentException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());

            return;
        }

        if (! $result) {
            return;
        }

        if ($result->found === 0) {
            Flux::toast(text: 'No git repositories found in that folder');

            return;
        }

        $parts = [];
        if ($result->registered > 0) {
            $parts[] = "{$result->registered} new";
        }
        if ($result->alreadyTracked > 0) {
            $parts[] = "{$result->alreadyTracked} already tracked";
        }

        if ($result->failed > 0) {
            $parts[] = "{$result->failed} failed";
        }

        Flux::toast(text: implode(', ', $parts));

        if ($result->registered > 0) {
            $this->dispatch('projects-changed');
        }
    }
};
?>

<div>
    @native
        @if($variant === 'expanded')
            <div class="flex items-center justify-center gap-3">
                <flux:button wire:click="openRepository" wire:loading.attr="disabled" variant="primary" size="sm" icon="folder-open" icon:variant="outline">
                    <span wire:loading.remove wire:target="openRepository">Add a repo</span>
                    <span wire:loading wire:target="openRepository">Opening...</span>
                </flux:button>
                <flux:button wire:click="scanDirectory" wire:loading.attr="disabled" variant="ghost" size="sm" icon="rectangle-stack" icon:variant="outline">
                    <span wire:loading.remove wire:target="scanDirectory">Scan folder for repos</span>
                    <span wire:loading wire:target="scanDirectory">Scanning...</span>
                </flux:button>
            </div>
        @else
            <flux:dropdown position="bottom" align="end">
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="plus"
                    icon:variant="outline"
                    class="text-gh-muted hover:text-gh-text"
                />

                <flux:menu>
                    <flux:menu.item wire:click="openRepository" icon="folder-open" icon:variant="outline">
                        Add a repo
                    </flux:menu.item>
                    <flux:menu.item wire:click="scanDirectory" icon="rectangle-stack" icon:variant="outline">
                        Scan folder for repos
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif
    @endnative
</div>
