<?php

namespace App\DTOs;

class Comment
{
    public function __construct(
        public readonly string $id,
        public readonly string $file,
        public readonly string $side, // 'left', 'right', 'file'
        public readonly ?int $startLine,
        public readonly ?int $endLine,
        public readonly string $body,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'file' => $this->file,
            'side' => $this->side,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'body' => $this->body,
        ];
    }
}
