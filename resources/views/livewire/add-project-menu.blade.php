<?php

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\ScanDirectoryDialogAction;
use Flux\Flux;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Livewire\Component;

new class extends Component {
    public string $variant = 'dropdown';

    public function openRepository(): void
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';

        try {
            $project = app(OpenRepositoryDialogAction::class)->handle();

            if (! $project) {
                $outcome = OpenRepositoryDialogAction::outcomeForNullProject();

                return;
            }

            Context::add('rfa.project_id', $project->id);
            Context::add('rfa.project_slug', $project->slug);

            $this->redirect(route('review-page', ['slug' => $project->slug]), navigate: true);
        } catch (\Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'open_repository_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('project.opened');
        }
    }

    public function scanDirectory(): void
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';

        try {
            $outcome = $this->scanAndReport();
        } catch (\Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'directory_scan_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('directory.scanned');
        }
    }

    /**
     * Run the scan, toast its result, and return the outcome for the
     * canonical event the caller owns.
     */
    private function scanAndReport(): string
    {
        try {
            $result = app(ScanDirectoryDialogAction::class)->handle();
        } catch (\InvalidArgumentException $e) {
            Context::add('rfa.reason', 'unscannable_directory');

            Flux::toast(variant: 'danger', text: $e->getMessage());

            return 'rejected';
        }

        if (! $result) {
            return 'cancelled';
        }

        Context::add('rfa.repos_found', $result->found);
        Context::add('rfa.repos_registered', $result->registered);
        Context::add('rfa.repos_already_tracked', $result->alreadyTracked);
        Context::add('rfa.repos_failed', $result->failed);

        if ($result->found === 0) {
            Flux::toast(text: 'No git repositories found in that folder');

            return 'completed';
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

        return $result->failed > 0 ? 'partial' : 'completed';
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
                    <flux:menu.item wire:click="openRepository" wire:loading.attr="disabled" icon="folder-open" icon:variant="outline">
                        <span wire:loading.remove wire:target="openRepository">Add a repo</span>
                        <span wire:loading wire:target="openRepository">Opening...</span>
                    </flux:menu.item>
                    <flux:menu.item wire:click="scanDirectory" wire:loading.attr="disabled" icon="rectangle-stack" icon:variant="outline">
                        <span wire:loading.remove wire:target="scanDirectory">Scan folder for repos</span>
                        <span wire:loading wire:target="scanDirectory">Scanning...</span>
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endif
    @endnative
</div>
