<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\Carbon;

class ReviewFilePair
{
    /**
     * Regex for RFA review file basenames: {YYYYMMDD}_{HHMMSS}_comments_{hash}
     */
    private const BASENAME_PATTERN = '/^(\d{8}_\d{6})_comments_[A-Za-z0-9]+$/';

    /**
     * @param  ?array<string, mixed>  $jsonFile
     * @param  ?array<string, mixed>  $mdFile
     */
    public function __construct(
        public readonly string $basename,
        public readonly ?array $jsonFile,
        public readonly ?array $mdFile,
        public readonly ?Carbon $createdAt,
    ) {}

    /**
     * Extract the shared basename from an .rfa/ review file path.
     * Returns null if the path doesn't match the expected pattern.
     */
    public static function extractBasename(string $path): ?string
    {
        if (! preg_match('#(?:^|/)\.rfa/([^/]+)\.(json|md)$#', $path, $m)) {
            return null;
        }

        if (! preg_match(self::BASENAME_PATTERN, $m[1])) {
            return null;
        }

        return $m[1];
    }

    /**
     * Parse the creation timestamp from a basename.
     */
    public static function parseTimestamp(string $basename): ?Carbon
    {
        if (! preg_match('/^(\d{8})_(\d{6})_comments_/', $basename, $m)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Ymd_His', $m[1].'_'.$m[2]);
        } catch (\Exception) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => 'review-'.hash('xxh128', $this->basename),
            'basename' => $this->basename,
            'displayName' => $this->createdAt?->format('M j, g:i A') ?? $this->basename,
            'jsonFile' => $this->jsonFile,
            'mdFile' => $this->mdFile,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'createdAtHuman' => $this->createdAt?->diffForHumans(),
        ];
    }
}
