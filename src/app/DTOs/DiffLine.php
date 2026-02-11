<?php

namespace App\DTOs;

class DiffLine
{
    public function __construct(
        public readonly string $type, // 'context', 'add', 'remove'
        public readonly string $content,
        public readonly ?int $oldLineNum,
        public readonly ?int $newLineNum,
    ) {}
}
