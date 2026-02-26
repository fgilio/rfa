<?php

declare(strict_types=1);

namespace App\DTOs;

class FileListEntry
{
    /** @var list<string> */
    private const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico', 'bmp', 'avif'];

    public function __construct(
        public readonly string $path,
        public readonly string $status, // added, deleted, modified, renamed, binary
        public readonly ?string $oldPath,
        public readonly int $additions,
        public readonly int $deletions,
        public readonly bool $isBinary,
        public readonly bool $isUntracked,
        public readonly ?string $lastModified = null,
    ) {}

    public function getId(): string
    {
        return 'file-'.hash('xxh128', $this->path);
    }

    public function isImage(): bool
    {
        $ext = strtolower(pathinfo($this->path, PATHINFO_EXTENSION));

        return in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'path' => $this->path,
            'status' => $this->status,
            'oldPath' => $this->oldPath,
            'additions' => $this->additions,
            'deletions' => $this->deletions,
            'isBinary' => $this->isBinary,
            'isUntracked' => $this->isUntracked,
            'isImage' => $this->isImage(),
            'lastModified' => $this->lastModified,
        ];
    }
}
