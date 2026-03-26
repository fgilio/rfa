<?php

declare(strict_types=1);

namespace App\DTOs;

class BranchEntry
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isCurrent,
        public readonly bool $isRemote,
        public readonly ?string $remote = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'isCurrent' => $this->isCurrent,
            'isRemote' => $this->isRemote,
            'remote' => $this->remote,
        ];
    }
}
