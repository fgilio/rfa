<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\UpdaterStatus;

/**
 * A single snapshot of auto-updater state.
 *
 * `startedAt` and `simulateTerminalState` only exist for the development-only
 * simulated check, which needs to know when it began and whether it is
 * allowed to settle itself. Neither is view state.
 */
final readonly class UpdaterState
{
    public function __construct(
        public UpdaterStatus $status,
        public ?string $version = null,
        public ?string $releaseNotes = null,
        public int $percent = 0,
        public ?int $startedAt = null,
        public bool $simulateTerminalState = false,
    ) {}

    /**
     * Rebuild a state from its cached array, tolerating anything an older
     * build wrote: missing keys, a percent stored as a float or a numeric
     * string, and status values this build no longer knows. An unreadable
     * payload yields null, which callers treat as "no state".
     *
     * @param  array<string, mixed>  $cached
     */
    public static function fromArray(array $cached): ?self
    {
        $status = UpdaterStatus::tryFrom(is_string($cached['status'] ?? null) ? $cached['status'] : '');

        if ($status === null) {
            return null;
        }

        return new self(
            status: $status,
            version: is_string($cached['version'] ?? null) ? $cached['version'] : null,
            releaseNotes: is_string($cached['releaseNotes'] ?? null) ? $cached['releaseNotes'] : null,
            percent: is_numeric($cached['percent'] ?? null) ? (int) round((float) $cached['percent']) : 0,
            startedAt: is_numeric($cached['startedAt'] ?? null) ? (int) $cached['startedAt'] : null,
            simulateTerminalState: ($cached['simulateTerminalState'] ?? false) === true,
        );
    }

    /**
     * The cache payload. Every field is written unconditionally; `fromArray()`
     * defaults anything an older, sparser entry left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'version' => $this->version,
            'releaseNotes' => $this->releaseNotes,
            'percent' => $this->percent,
            'startedAt' => $this->startedAt,
            'simulateTerminalState' => $this->simulateTerminalState,
        ];
    }

    /**
     * The scalars the update banner renders. Deliberately narrower than the
     * state itself: the simulation bookkeeping never reaches public Livewire
     * component state.
     *
     * @return array{status: string, version: ?string, releaseNotes: ?string, downloadPercent: int}
     */
    public function toViewSnapshot(): array
    {
        return [
            'status' => $this->status->value,
            'version' => $this->version,
            'releaseNotes' => $this->releaseNotes,
            'downloadPercent' => $this->percent,
        ];
    }
}
