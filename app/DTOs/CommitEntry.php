<?php

declare(strict_types=1);

namespace App\DTOs;

class CommitEntry
{
    public function __construct(
        public readonly string $hash,
        public readonly string $shortHash,
        public readonly string $message,
        public readonly string $author,
        public readonly string $relativeDate,
        public readonly string $date,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'hash' => $this->hash,
            'shortHash' => $this->shortHash,
            'message' => $this->message,
            'author' => $this->author,
            'relativeDate' => $this->relativeDate,
            'date' => $this->date,
        ];
    }
}
