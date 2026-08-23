<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ReviewConfig
{
    public function __construct(
        public int $diffMaxBytes,
        public int $sourceMaxBytes,
        public int $cacheTtlHours,
        public int $defaultContextLines,
        public bool $movedLineDetection,
        public string $movedLineMode,
    ) {}

    /**
     * Cache-identity fingerprint for every setting that shapes a stored diff, so
     * a cached entry is only ever read back by a run that would have produced
     * it. Each covered setting changes the bytes we store:
     *
     * - moved-line detection and mode: git colorizes moves and the parser bakes
     *   those markers into the hunks. The mode only matters while detection is
     *   on, so a disabled run collapses to a single bucket.
     * - `diffMaxBytes`: decides whether a file is diffed at all or stored as a
     *   `too-large` outcome. Raising the limit must not keep serving the skip.
     * - `defaultContextLines`: sets the `-U` width of the stored hunks.
     *
     * `sourceMaxBytes` and `cacheTtlHours` are deliberately absent: neither
     * changes the diff content, so keying on them would discard good entries.
     */
    public function cacheFingerprint(): string
    {
        return implode('|', [
            $this->movedLineDetection ? 'm1-'.$this->movedLineMode : 'm0',
            'b'.$this->diffMaxBytes,
            'c'.$this->defaultContextLines,
        ]);
    }

    /**
     * @return array{
     *     diffMaxBytes: int,
     *     sourceMaxBytes: int,
     *     cacheTtlHours: int,
     *     defaultContextLines: int,
     *     movedLineDetection: bool,
     *     movedLineMode: string
     * }
     */
    public function toArray(): array
    {
        return [
            'diffMaxBytes' => $this->diffMaxBytes,
            'sourceMaxBytes' => $this->sourceMaxBytes,
            'cacheTtlHours' => $this->cacheTtlHours,
            'defaultContextLines' => $this->defaultContextLines,
            'movedLineDetection' => $this->movedLineDetection,
            'movedLineMode' => $this->movedLineMode,
        ];
    }
}
