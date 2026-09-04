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
        public readonly bool $isSymlink = false,
        public readonly ?string $symlinkTarget = null,
        public readonly ?string $fileSize = null,
        public readonly bool $isExternal = false,
        public readonly ?string $externalAbsolutePath = null,
        public readonly bool $isWholeFile = false,
        // Raw mtime + byte size used by the review page's softRefresh
        // change-detection. Kept separate from the human-readable
        // `lastModified` / `fileSize` because those bucket aggressively
        // (e.g. `diffForHumans` short-form) and miss rapid in-place edits.
        public readonly ?int $mtime = null,
        public readonly ?int $byteSize = null,
    ) {}

    public static function idForPath(string $path): string
    {
        return 'file-'.hash('xxh128', $path);
    }

    public function getId(): string
    {
        return self::idForPath($this->path);
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
            'isSymlink' => $this->isSymlink,
            'symlinkTarget' => $this->symlinkTarget,
            'fileSize' => $this->fileSize,
            'isExternal' => $this->isExternal,
            'externalAbsolutePath' => $this->externalAbsolutePath,
            'isWholeFile' => $this->isWholeFile,
            'mtime' => $this->mtime,
            'byteSize' => $this->byteSize,
        ];
    }
}
