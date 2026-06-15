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
