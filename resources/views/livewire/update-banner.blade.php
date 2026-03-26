<?php

use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Desktop\Facades\AutoUpdater;

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
        $state = Cache::get('native-update-state');

        if (! $state) {
            $this->resetState();

            return;
        }

        $this->applyState($this->resolveDevCheckState($state));
    }

    #[On('native:Native\\Desktop\\Events\\Menu\\MenuItemClicked')]
    public function handleNativeMenuItemClicked(array $item): void
    {
        if (($item['id'] ?? null) !== 'check-updates') {
            return;
        }

        $this->storeState(
            $this->checkingState(),
            now()->addMinutes(2),
        );
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\CheckingForUpdate')]
    public function handleCheckingForUpdate(): void
    {
        $this->storeState(
            $this->checkingState(),
            now()->addMinutes(2),
        );
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateAvailable')]
    public function handleUpdateAvailable(string $version, array|string|null $releaseNotes = null): void
    {
        $this->storeState([
            'status' => 'downloading',
            'version' => $version,
            'releaseNotes' => $this->normalizeReleaseNotes($releaseNotes),
            'percent' => 0,
        ], now()->addMinutes(30));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\DownloadProgress')]
    public function handleDownloadProgress(int|float $percent): void
    {
        $this->storeState([
            'status' => 'downloading',
            'version' => $this->version,
            'releaseNotes' => $this->releaseNotes,
            'percent' => (int) round($percent),
        ], now()->addMinutes(30));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateDownloaded')]
    public function handleUpdateDownloaded(string $version, array|string|null $releaseNotes = null): void
    {
        $this->storeState([
            'status' => 'ready',
            'version' => $version,
            'releaseNotes' => $this->normalizeReleaseNotes($releaseNotes),
            'percent' => 100,
        ], now()->addHours(24));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\UpdateNotAvailable')]
    public function handleUpdateNotAvailable(): void
    {
        $this->storeState(['status' => 'up-to-date'], now()->addSeconds(10));
    }

    #[On('native:Native\\Desktop\\Events\\AutoUpdater\\Error')]
    public function handleUpdateError(): void
    {
        $this->storeState(['status' => 'error'], now()->addMinutes(5));
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
        $this->resetState();
    }

    /** @return array<string, mixed> */
    private function checkingState(): array
    {
        return [
            'status' => 'checking',
            'startedAt' => now()->timestamp,
            'simulateTerminalState' => config('app.debug'),
        ];
    }

    /** @param array<string, mixed> $state */
    private function applyState(array $state): void
    {
        $this->status = $state['status'] ?? null;
        $this->version = $state['version'] ?? null;
        $this->releaseNotes = $state['releaseNotes'] ?? null;
        $this->downloadPercent = $state['percent'] ?? 0;
    }

    private function resetState(): void
    {
        $this->status = null;
        $this->version = null;
        $this->releaseNotes = null;
        $this->downloadPercent = 0;
    }

    /** @param array<string, mixed> $state */
    private function storeState(array $state, \DateTimeInterface $ttl): void
    {
        Cache::put('native-update-state', $state, $ttl);

        $this->applyState($state);
    }

    /** @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function resolveDevCheckState(array $state): array
    {
        if (! config('app.debug')) {
            return $state;
        }

        if (($state['status'] ?? null) !== 'checking') {
            return $state;
        }

        if (($state['simulateTerminalState'] ?? false) !== true) {
            return $state;
        }

        $startedAt = $state['startedAt'] ?? null;

        if (! is_int($startedAt) || (now()->timestamp - $startedAt) < 2) {
            return $state;
        }

        $state = [
            'status' => 'checked-dev',
        ];

        Cache::put('native-update-state', $state, now()->addSeconds(20));

        return $state;
    }

    /** @param array<string>|string|null $notes */
    private function normalizeReleaseNotes(array|string|null $notes): ?string
    {
        return is_array($notes) ? implode(' ', $notes) : $notes;
    }
};
?>

<div
    wire:poll.{{ match(true) { $status === null => '5s', in_array($status, ['checking', 'downloading']) => '2s', default => '30s' } }}="refreshState"
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
    @elseif($status === 'up-to-date')
        <div
            class="bg-gh-surface border-b border-gh-border px-4 py-2 font-mono text-xs text-gh-green flex items-center justify-center gap-2"
            role="status"
        >
            <flux:icon icon="check-circle" variant="outline" class="!size-3.5" />
            You're up to date
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
</div>
