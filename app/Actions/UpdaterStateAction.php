<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\UpdaterStateService;

/**
 * The updater's one entry point for every interface: the native provider's
 * event listeners, the "Check for Updates..." menu item, and the update
 * banner in the renderer. Each of them reports what happened and reads back
 * the same view snapshot; none of them touches the cache.
 *
 * @phpstan-type UpdaterViewSnapshot array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int}
 */
final readonly class UpdaterStateAction
{
    /** What the banner renders when there is no state to show. */
    private const array IDLE_SNAPSHOT = [
        'status' => null,
        'version' => null,
        'releaseNotes' => null,
        'downloadPercent' => 0,
    ];

    public function __construct(
        private UpdaterStateService $store,
    ) {}

    /**
     * The scalars the banner renders, resolved for right now.
     *
     * @return UpdaterViewSnapshot
     */
    public function handle(): array
    {
        return $this->store->resolve()?->toViewSnapshot() ?? self::IDLE_SNAPSHOT;
    }

    /** @return UpdaterViewSnapshot */
    public function beginCheck(): array
    {
        return $this->store->beginCheck()->toViewSnapshot();
    }

    /**
     * @param  array<string>|string|null  $releaseNotes
     * @return UpdaterViewSnapshot
     */
    public function recordAvailable(string $version, array|string|null $releaseNotes = null): array
    {
        return $this->store->recordAvailable($version, $releaseNotes)->toViewSnapshot();
    }

    /** @return UpdaterViewSnapshot */
    public function recordProgress(int|float $percent): array
    {
        return $this->store->recordProgress($percent)->toViewSnapshot();
    }

    /**
     * @param  array<string>|string|null  $releaseNotes
     * @return UpdaterViewSnapshot
     */
    public function recordDownloaded(string $version, array|string|null $releaseNotes = null): array
    {
        return $this->store->recordDownloaded($version, $releaseNotes)->toViewSnapshot();
    }

    /** @return UpdaterViewSnapshot */
    public function recordUpToDate(): array
    {
        return $this->store->recordUpToDate()->toViewSnapshot();
    }

    /** @return UpdaterViewSnapshot */
    public function recordError(): array
    {
        return $this->store->recordError()->toViewSnapshot();
    }

    /** @return UpdaterViewSnapshot */
    public function dismiss(): array
    {
        $this->store->clear();

        return self::IDLE_SNAPSHOT;
    }
}
