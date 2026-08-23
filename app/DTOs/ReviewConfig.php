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
     * Cache-identity fingerprint for the moved-line settings. Git colorizes
     * moves and the parser bakes those markers into the stored diff, so a
     * cached diff is only valid for the settings that produced it. The mode
     * only matters while detection is on, so a disabled run collapses to a
     * single bucket.
     */
    public function movedLineFingerprint(): string
    {
        return $this->movedLineDetection ? 'm1-'.$this->movedLineMode : 'm0';
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
