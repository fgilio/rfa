<?php

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
}
