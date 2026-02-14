<?php

namespace App\DTOs;

class FileListEntry
{
    public function __construct(
        public readonly string $path,
        public readonly string $status, // added, deleted, modified, renamed, binary
        public readonly ?string $oldPath,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly bool $isBinary,
        public readonly bool $isUntracked,
    ) {}
}
