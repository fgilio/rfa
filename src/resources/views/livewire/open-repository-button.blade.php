<?php

use App\Actions\OpenRepositoryDialogAction;
use Livewire\Component;

new class extends Component {
    public string $variant = 'icon';

    public function openRepositoryDialog(): void
    {
        $project = app(OpenRepositoryDialogAction::class)->handle();

        if ($project) {
            $this->redirect(route('review-page', ['slug' => $project->slug]));
        }
    }
};
?>

@native
    @if($variant === 'primary')
        <flux:button wire:click="openRepositoryDialog" wire:loading.attr="disabled" variant="primary" size="sm" icon="folder-open" icon:variant="outline">
            <span wire:loading.remove>Open Repository</span>
            <span wire:loading>Opening...</span>
        </flux:button>
    @else
        <flux:button
            wire:click="openRepositoryDialog"
            wire:loading.attr="disabled"
            variant="ghost"
            size="sm"
            icon="folder-open"
            icon:variant="outline"
            class="text-gh-muted hover:text-gh-text"
        />
    @endif
@endnative
