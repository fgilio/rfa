<?php

use App\Actions\UpdaterStateAction;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Facades\AutoUpdater;

/**
 * Thin view over the updater state store.
 *
 * @phpstan-import-type UpdaterViewSnapshot from UpdaterStateAction
 *
 * The renderer sees the same updater events as the main process, so this
 * component reports them to `UpdaterStateAction` and renders the snapshot it
 * gets back. Cache identity, TTLs, normalization and which transitions are
 * legal all live behind that action; the four fields below are only what the
 * template needs.
 */
new class extends Component
{
    /** One of the `UpdaterStatus` values, or null when there is nothing to show. */
    public ?string $status = null;

    public ?string $version = null;

    public ?string $releaseNotes = null;

    public int $downloadPercent = 0;

    public function mount(): void
    {
        $this->refreshState();
    }

    public function refreshState(): void
    {
        $this->apply($this->updater()->handle());
    }

    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    public function handleNativeMenuItemClicked(array $item): void
    {
        if (($item['id'] ?? null) !== 'check-updates') {
            return;
        }

        $this->apply($this->updater()->beginCheck());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\CheckingForUpdate')]
    public function handleCheckingForUpdate(): void
    {
        $this->apply($this->updater()->beginCheck());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable')]
    public function handleUpdateAvailable(string $version, array|string|null $releaseNotes = null): void
    {
        $this->apply($this->updater()->recordAvailable($version, $releaseNotes));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\DownloadProgress')]
    public function handleDownloadProgress(int|float $percent): void
    {
        $this->apply($this->updater()->recordProgress($percent));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded')]
    public function handleUpdateDownloaded(string $version, array|string|null $releaseNotes = null): void
    {
        $this->apply($this->updater()->recordDownloaded($version, $releaseNotes));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateNotAvailable')]
    public function handleUpdateNotAvailable(): void
    {
        $this->apply($this->updater()->recordUpToDate());
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\Error')]
    public function handleUpdateError(): void
    {
        $this->apply($this->updater()->recordError());
    }

    public function restartAndUpdate(): void
    {
        try {
            AutoUpdater::quitAndInstall();
        } catch (\Throwable) {
            $this->apply($this->updater()->recordError());
            $this->dispatch('restart-failed');
        }
    }

    public function dismiss(): void
    {
        $this->apply($this->updater()->dismiss());
    }

    private function updater(): UpdaterStateAction
    {
        return app(UpdaterStateAction::class);
    }

    /** @param UpdaterViewSnapshot $snapshot */
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
    data-testid="update-banner"
    wire:smart-poll="refreshState"
    data-focus="{{ $cadence['focus'] }}"
    data-blur="{{ $cadence['blur'] }}"
>
    @if($status)
        <div
            data-testid="update-notification"
            wire:key="update-notification-{{ $status }}"
            wire:transition.opacity
            class="fixed top-3 left-1/2 z-[70] w-max max-w-[calc(100vw-2rem)] -translate-x-1/2 pointer-events-none motion-reduce:transition-none"
            role="{{ $status === 'error' ? 'alert' : 'status' }}"
            aria-live="{{ $status === 'error' ? 'assertive' : 'polite' }}"
            aria-atomic="true"
        >
            <div class="pointer-events-auto relative overflow-hidden rounded-lg border border-gh-border/80 bg-gh-surface/95 shadow-lg shadow-black/10 backdrop-blur-md">
                <div class="flex min-h-9 items-center gap-2.5 px-3 py-2 font-mono text-xs text-gh-text">
                    @if($status === 'checking')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-link/10 text-gh-link">
                            <flux:icon icon="arrow-path" variant="outline" class="!size-3.5 animate-spin motion-reduce:animate-none" aria-hidden="true" />
                        </span>
                        <span>Checking for updates...</span>
                    @elseif($status === 'downloading')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-link/10 text-gh-link">
                            <flux:icon icon="arrow-down-tray" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </span>
                        <span>Downloading v{{ $version }}...</span>
                        <span class="tabular-nums text-gh-muted">{{ $downloadPercent }}%</span>
                    @elseif($status === 'ready')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-green/10 text-gh-green">
                            <flux:icon icon="arrow-up-circle" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </span>
                        <span>v{{ $version }} ready</span>
                        @if($releaseNotes)
                            <span class="max-w-xs truncate text-gh-muted" title="{{ $releaseNotes }}">{{ Str::limit($releaseNotes, 60) }}</span>
                        @endif
                        <button
                            type="button"
                            x-data
                            @click="$dispatch('restart-started')"
                            wire:click="restartAndUpdate"
                            class="rounded px-1.5 py-0.5 font-medium text-gh-link transition-colors hover:bg-gh-link/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gh-link"
                        >
                            Restart to update
                        </button>
                    @elseif($status === 'up-to-date')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-green/10 text-gh-green">
                            <flux:icon icon="check" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </span>
                        <span>You're up to date</span>
                    @elseif($status === 'checked-dev')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-link/10 text-gh-link">
                            <flux:icon icon="information-circle" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </span>
                        <span>Checked for updates</span>
                        <span class="text-gh-muted">Dev build - NativePHP updater does not complete here.</span>
                    @elseif($status === 'error')
                        <span class="grid size-5 shrink-0 place-items-center rounded bg-gh-red/10 text-gh-red">
                            <flux:icon icon="exclamation-triangle" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </span>
                        <span>Update check failed</span>
                    @endif

                    @if(in_array($status, ['ready', 'up-to-date', 'error'], true))
                        <button
                            type="button"
                            wire:click="dismiss"
                            class="grid size-5 shrink-0 place-items-center rounded text-gh-muted transition-colors hover:bg-gh-border/60 hover:text-gh-text focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gh-link"
                            aria-label="Dismiss update notification"
                        >
                            <flux:icon icon="x-mark" variant="outline" class="!size-3.5" aria-hidden="true" />
                        </button>
                    @endif
                </div>

                @if($status === 'downloading')
                    <div
                        class="absolute inset-x-0 bottom-0 h-0.5 bg-gh-border"
                        role="progressbar"
                        aria-label="Update download progress"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        aria-valuenow="{{ $downloadPercent }}"
                    >
                        <div
                            class="h-full bg-gh-link transition-[width] duration-200 ease-out motion-reduce:transition-none"
                            style="width: {{ $downloadPercent }}%"
                        ></div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <x-restart-overlay :version="$version" />
</div>
