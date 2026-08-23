<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\UpdaterState;
use App\Enums\UpdaterStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * The single owner of auto-updater state.
 *
 * Updater events reach the app through two processes — the main process
 * (`NativeAppServiceProvider`, `HandleMenuItemClicked`) and the renderer
 * (the update banner's `native:` listeners) — and both used to write the
 * cache themselves, each repeating the key, the per-status TTL and the
 * normalization. Everything that decides what a state *is* lives here now;
 * callers only say what happened.
 */
final class UpdaterStateService
{
    public const string CACHE_KEY = 'native-update-state';

    /** A simulated dev check settles this long after it started. */
    private const int DEV_SETTLE_SECONDS = 2;

    public function current(): ?UpdaterState
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? UpdaterState::fromArray($cached) : null;
    }

    /**
     * The state to render right now.
     *
     * Two things can only be decided at read time, so they are resolved here
     * rather than by whoever happens to be polling: an update that is "ready"
     * but names the version already running has been installed and is
     * dropped, and a simulated dev check that has had time to settle becomes
     * a terminal dev state instead of spinning forever.
     */
    public function resolve(): ?UpdaterState
    {
        $state = $this->current();

        if ($state === null) {
            return null;
        }

        if ($state->status === UpdaterStatus::Ready && $state->version === config('nativephp.version')) {
            $this->clear();

            return null;
        }

        return $this->settleSimulatedDevCheck($state);
    }

    public function beginCheck(): UpdaterState
    {
        return $this->put(new UpdaterState(
            status: UpdaterStatus::Checking,
            startedAt: now()->timestamp,
            // A dev build's updater never reports back, so let the check
            // settle itself into a terminal state on the next read.
            simulateTerminalState: (bool) config('app.debug'),
        ));
    }

    /** @param array<string>|string|null $releaseNotes */
    public function recordAvailable(string $version, array|string|null $releaseNotes = null): UpdaterState
    {
        return $this->put(new UpdaterState(
            status: UpdaterStatus::Downloading,
            version: $version,
            releaseNotes: self::normalizeReleaseNotes($releaseNotes),
            percent: 0,
        ));
    }

    /**
     * Progress only moves a download forward. An already-downloaded update is
     * left alone: a late progress event must not knock a ready state back to
     * downloading and hide the restart affordance.
     */
    public function recordProgress(int|float $percent): UpdaterState
    {
        $current = $this->current();

        if ($current?->status === UpdaterStatus::Ready) {
            return $current;
        }

        $percent = (int) round($percent);

        // The updater reports progress per response chunk, but the banner only
        // renders whole percents, so most events carry a figure already
        // stored. Writing it again would cost a cache round-trip per chunk.
        if ($current?->status === UpdaterStatus::Downloading && $current->percent === $percent) {
            return $current;
        }

        return $this->put(new UpdaterState(
            status: UpdaterStatus::Downloading,
            version: $current?->version,
            releaseNotes: $current?->releaseNotes,
            percent: $percent,
        ));
    }

    /** @param array<string>|string|null $releaseNotes */
    public function recordDownloaded(string $version, array|string|null $releaseNotes = null): UpdaterState
    {
        return $this->put(new UpdaterState(
            status: UpdaterStatus::Ready,
            version: $version,
            releaseNotes: self::normalizeReleaseNotes($releaseNotes),
            percent: 100,
        ));
    }

    public function recordUpToDate(): UpdaterState
    {
        return $this->put(new UpdaterState(status: UpdaterStatus::UpToDate));
    }

    public function recordError(): UpdaterState
    {
        return $this->put(new UpdaterState(status: UpdaterStatus::Error));
    }

    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Release notes arrive as HTML, or as one entry per release when the feed
     * spans several. The banner renders them as plain text.
     *
     * @param  array<string>|string|null  $notes
     */
    public static function normalizeReleaseNotes(array|string|null $notes): ?string
    {
        $text = is_array($notes) ? implode(' ', $notes) : $notes;

        return $text === null || $text === '' ? $text : trim(strip_tags($text));
    }

    private function put(UpdaterState $state): UpdaterState
    {
        Cache::put(self::CACHE_KEY, $state->toArray(), $this->ttlFor($state->status));

        return $state;
    }

    /**
     * How long a state stays believable without a follow-up event. A check
     * that never reports back should stop spinning within a couple of
     * minutes; a downloaded update stays actionable for a day.
     */
    private function ttlFor(UpdaterStatus $status): CarbonInterface
    {
        return match ($status) {
            UpdaterStatus::Checking => now()->addMinutes(2),
            UpdaterStatus::Downloading => now()->addMinutes(30),
            UpdaterStatus::Ready => now()->addHours(24),
            UpdaterStatus::UpToDate => now()->addSeconds(10),
            UpdaterStatus::CheckedDev => now()->addSeconds(20),
            UpdaterStatus::Error => now()->addMinutes(5),
        };
    }

    private function settleSimulatedDevCheck(UpdaterState $state): UpdaterState
    {
        if (! config('app.debug') || ! $state->simulateTerminalState) {
            return $state;
        }

        if ($state->status !== UpdaterStatus::Checking || $state->startedAt === null) {
            return $state;
        }

        if ((now()->timestamp - $state->startedAt) < self::DEV_SETTLE_SECONDS) {
            return $state;
        }

        return $this->put(new UpdaterState(status: UpdaterStatus::CheckedDev));
    }
}
