<?php

declare(strict_types=1);

namespace App\DTOs;

class DiffLine
{
    public function __construct(
        public readonly string $type, // 'context', 'add', 'remove'
        public readonly string $content,
        public readonly ?int $oldLineNum,
        public readonly ?int $newLineNum,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'content' => $this->content,
            'oldLineNum' => $this->oldLineNum,
            'newLineNum' => $this->newLineNum,
        ];
    }
}
