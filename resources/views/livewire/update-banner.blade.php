<?php

use App\Actions\UpdaterStateAction;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Facades\AutoUpdater;

/**
 * Thin view over the updater state store.
 *
 * The renderer sees the same updater events as the main process, so this
 * component reports them to `UpdaterStateAction` and renders the snapshot it
 * gets back. Cache identity, TTLs, normalization and which transitions are
 * legal all live behind that action; the four fields below are only what the
 * template needs.
 */
new class extends Component
{
    public ?string $status = null; // checking, downloading, ready, up-to-date, checked-dev, error

    public ?string $version = null;

    public ?string $releaseNotes = null;

    public int $downloadPercent = 0;

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $this->apply(app(UpdaterStateAction::class)->handle());
    }

    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    public function handleNativeMenuItemClicked(array $item): void
    {
        if (($item['id'] ?? null) !== 'check-updates') {
            return;
        }

        $this->apply(app(UpdaterStateAction::class)->beginCheck());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\CheckingForUpdate')]
    public function handleCheckingForUpdate(): void
    {
        $this->apply(app(UpdaterStateAction::class)->beginCheck());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable')]
    public function handleUpdateAvailable(string $version, array|string|null $releaseNotes = null): void
    {
        $this->apply(app(UpdaterStateAction::class)->recordAvailable($version, $releaseNotes));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\DownloadProgress')]
    public function handleDownloadProgress(int|float $percent): void
    {
        $this->apply(app(UpdaterStateAction::class)->recordProgress($percent));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded')]
    public function handleUpdateDownloaded(string $version, array|string|null $releaseNotes = null): void
    {
        $this->apply(app(UpdaterStateAction::class)->recordDownloaded($version, $releaseNotes));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateNotAvailable')]
    public function handleUpdateNotAvailable(): void
    {
        $this->apply(app(UpdaterStateAction::class)->recordUpToDate());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\Error')]
    public function handleUpdateError(): void
    {
        $this->apply(app(UpdaterStateAction::class)->recordError());
    }

    public function restartAndUpdate(): void
    {
        try {
            AutoUpdater::quitAndInstall();
        } catch (\Throwable) {
            $this->apply(app(UpdaterStateAction::class)->recordError());
            $this->dispatch('restart-failed');
        }
    }

    public function dismiss(): void
    {
        $this->apply(app(UpdaterStateAction::class)->dismiss());
    }

    /** @param array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} $snapshot */
    private function apply(array $snapshot): void
    {
        $this->status = $snapshot['status'];
        $this->version = $snapshot['version'];
        $this->releaseNotes = $snapshot['releaseNotes'];
        $this->downloadPercent = $snapshot['downloadPercent'];
    }

    /**
     * Smart-poll cadence per status. Active flows (checking/downloading) need a
     * tight focused loop so the progress UI feels live, but idle/terminal
     * states only need to catch cache TTL expiry — minutes are enough.
     *
     * @return array{focus: string, blur: string}
     */
    public function pollCadence(): array
    {
        return match (true) {
            in_array($this->status, ['checking', 'downloading'], true) => ['focus' => '2s', 'blur' => '30s'],
            $this->status === null => ['focus' => '1m', 'blur' => '30m'],
            default => ['focus' => '30s', 'blur' => '5m'],
        };
    }
};
?>

@php($cadence = $this->pollCadence())

<div
    wire:smart-poll="refreshState"
    data-focus="{{ $cadence['focus'] }}"
    data-blur="{{ $cadence['blur'] }}"
>
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
                    class="h-full bg-gh-link rounded-full transition-all duration-200 ease-out"
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
                x-data
                @click="$dispatch('restart-started')"
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
    @elseif($status === 'up-to-date')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-green flex items-center justify-center gap-2"
            role="status"
        >
            <flux:icon icon="check-circle" variant="outline" class="!size-3.5" />
            You're up to date
            <button
                wire:click="dismiss"
                class="text-gh-muted hover:text-gh-text"
                aria-label="Dismiss"
            >
                <flux:icon icon="x-mark" variant="outline" class="!size-3.5" />
            </button>
        </div>
    @elseif($status === 'checked-dev')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-link flex items-center justify-center gap-3"
            role="status"
        >
            <flux:icon icon="information-circle" variant="outline" class="!size-3.5" />
            <span>Checked for updates</span>
            <span class="text-gh-muted">Dev build - NativePHP updater does not complete here.</span>
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

    <x-restart-overlay :version="$version" />
</div>
