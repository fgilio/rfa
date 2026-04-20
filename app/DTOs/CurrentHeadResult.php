<?php

declare(strict_types=1);

namespace App\DTOs;

class CurrentHeadResult
{
    public function __construct(
        public readonly ?string $branch,
        public readonly string $sha,
        public readonly bool $detached,
        public readonly ?bool $targetExists = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'sha' => $this->sha,
            'detached' => $this->detached,
            'targetExists' => $this->targetExists,
        ];
    }
}
