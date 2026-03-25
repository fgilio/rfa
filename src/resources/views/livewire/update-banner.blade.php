<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Native\Desktop\Facades\AutoUpdater;

new class extends Component {
    public ?string $status = null; // checking, downloading, ready, error
    public ?string $version = null;
    public ?string $releaseNotes = null;
    public int $downloadPercent = 0;

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $state = Cache::get('native-update-state');

        if (! $state) {
            $this->status = null;
            $this->version = null;
            $this->releaseNotes = null;
            $this->downloadPercent = 0;

            return;
        }

        $this->status = $state['status'] ?? null;
        $this->version = $state['version'] ?? null;
        $this->releaseNotes = $state['releaseNotes'] ?? null;
        $this->downloadPercent = $state['percent'] ?? 0;
    }

    public function restartAndUpdate(): void
    {
        try {
            AutoUpdater::quitAndInstall();
        } catch (\Throwable) {
            Cache::put('native-update-state', ['status' => 'error'], now()->addMinutes(5));
            $this->status = 'error';
        }
    }

    public function dismiss(): void
    {
        Cache::forget('native-update-state');
        $this->status = null;
    }
};
?>

<div wire:poll.{{ $status === 'downloading' ? '3s' : '30s' }}="refreshState">
    @if($status === 'checking')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-muted flex items-center justify-center gap-2"
            role="status"
        >
            <flux:icon icon="arrow-path" variant="outline" class="!size-3.5 animate-spin" />
            Checking for updates...
        </div>
    @elseif($status === 'downloading')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-text flex items-center justify-center gap-3"
            role="status"
        >
            <span>Downloading v{{ $version }}...</span>
            <div class="w-24 h-1 bg-gh-border rounded-full overflow-hidden">
                <div
                    class="h-full bg-gh-link rounded-full transition-all duration-500 ease-out"
                    style="width: {{ $downloadPercent }}%"
                ></div>
            </div>
            <span class="text-gh-muted">{{ $downloadPercent }}%</span>
        </div>
    @elseif($status === 'ready')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-text flex items-center justify-center gap-3"
            role="status"
        >
            <span>v{{ $version }} ready</span>
            @if($releaseNotes)
                <span class="text-gh-muted truncate max-w-xs" title="{{ $releaseNotes }}">{{ Str::limit($releaseNotes, 60) }}</span>
            @endif
            <button
                wire:click="restartAndUpdate"
                class="text-gh-link hover:underline font-medium"
            >
                Restart to update
            </button>
            <button
                wire:click="dismiss"
                class="text-gh-muted hover:text-gh-text"
                aria-label="Dismiss"
            >
                <flux:icon icon="x-mark" variant="outline" class="!size-3.5" />
            </button>
        </div>
    @elseif($status === 'error')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-red flex items-center justify-center gap-3"
            role="status"
        >
            <flux:icon icon="exclamation-triangle" variant="outline" class="!size-3.5" />
            <span>Update check failed</span>
            <button
                wire:click="dismiss"
                class="text-gh-muted hover:text-gh-text"
                aria-label="Dismiss"
            >
                <flux:icon icon="x-mark" variant="outline" class="!size-3.5" />
            </button>
        </div>
    @endif
</div>
