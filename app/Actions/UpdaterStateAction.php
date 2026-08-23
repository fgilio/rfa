<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\UpdaterStateService;

/**
 * The updater's one entry point for every interface: the native provider's
 * event listeners, the "Check for Updates..." menu item, and the update
 * banner in the renderer. Each of them reports what happened and reads back
 * the same view snapshot; none of them touches the cache.
 */
final readonly class UpdaterStateAction
{
    public function __construct(
        private UpdaterStateService $store,
    ) {}

    /**
     * The scalars the banner renders, resolved for right now.
     *
     * @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int}
     */
    public function handle(): array
    {
        return $this->store->resolve()?->toViewSnapshot() ?? [
            'status' => null,
            'version' => null,
            'releaseNotes' => null,
            'downloadPercent' => 0,
        ];
    }

    /** @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} */
    public function beginCheck(): array
    {
        return $this->store->beginCheck()->toViewSnapshot();
    }

    /**
     * @param  array<string>|string|null  $releaseNotes
     * @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int}
     */
    public function recordAvailable(string $version, array|string|null $releaseNotes = null): array
    {
        return $this->store->recordAvailable($version, $releaseNotes)->toViewSnapshot();
    }

    /** @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} */
    public function recordProgress(int|float $percent): array
    {
        return $this->store->recordProgress($percent)->toViewSnapshot();
    }

    /**
     * @param  array<string>|string|null  $releaseNotes
     * @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int}
     */
    public function recordDownloaded(string $version, array|string|null $releaseNotes = null): array
    {
        return $this->store->recordDownloaded($version, $releaseNotes)->toViewSnapshot();
    }

    /** @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} */
    public function recordUpToDate(): array
    {
        return $this->store->recordUpToDate()->toViewSnapshot();
    }

    /** @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} */
    public function recordError(): array
    {
        return $this->store->recordError()->toViewSnapshot();
    }

    /** @return array{status: ?string, version: ?string, releaseNotes: ?string, downloadPercent: int} */
    public function dismiss(): array
    {
        $this->store->clear();

        return $this->handle();
    }
}
