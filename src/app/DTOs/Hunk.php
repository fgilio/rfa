<?php

declare(strict_types=1);

namespace App\DTOs;

class Hunk
{
    public function __construct(
        public readonly string $header,
        public readonly int $oldStart,
        public readonly int $oldCount,
        public readonly int $newStart,
        public readonly int $newCount,
        /** @var DiffLine[] */
        public readonly array $lines,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'header' => $this->header,
            'oldStart' => $this->oldStart,
            'oldCount' => $this->oldCount,
            'newStart' => $this->newStart,
            'newCount' => $this->newCount,
            'lines' => array_map(fn (DiffLine $line) => $line->toArray(), $this->lines),
        ];
    }
}
