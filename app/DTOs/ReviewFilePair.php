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
     * Regex for RFA snapshot artifact paths: .rfa/{YYYYMMDD}_{HHMMSS}_snapshot_{hash}.json
     * (see ExportReviewSnapshotAction). Mirrors the comment-file naming so both
     * RFA-generated artifacts are filtered out of the reviewed source list.
     */
    private const SNAPSHOT_PATH_PATTERN = '#(?:^|/)\.rfa/\d{8}_\d{6}_snapshot_[A-Za-z0-9]+\.json$#';

    /**
     * @param  array<string, mixed>  $mdFile
     */
    public function __construct(
        public readonly string $basename,
        public readonly array $mdFile,
        public readonly ?Carbon $createdAt,
    ) {}

    /**
     * Validate a basename against the canonical pattern. Used by callers that
     * receive a basename from user input (e.g. the delete action).
     */
    public static function isValidBasename(string $basename): bool
    {
        return (bool) preg_match(self::BASENAME_PATTERN, $basename);
    }

    /**
     * Whether a path is any RFA-generated artifact (comment export or snapshot)
     * that should be hidden from the reviewed source-file list. Comment files
     * pair up via {@see self::extractBasename()}; snapshots stand alone.
     */
    public static function isArtifactPath(string $path): bool
    {
        return self::extractBasename($path) !== null
            || preg_match(self::SNAPSHOT_PATH_PATTERN, $path) === 1;
    }

    /**
     * Extract the shared basename from an .rfa/ review file path.
     * Returns null if the path doesn't match the expected pattern.
     */
    public static function extractBasename(string $path): ?string
    {
        if (! preg_match('#(?:^|/)\.rfa/([^/]+)\.md$#', $path, $m)) {
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

        return rescue(
            fn (): ?Carbon => Carbon::createFromFormat('Ymd_His', $m[1].'_'.$m[2]) ?: null,
            rescue: null,
            report: false,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => 'review-'.hash('xxh128', $this->basename),
            'basename' => $this->basename,
            'displayName' => $this->createdAt?->format('M j, g:i A') ?? $this->basename,
            'mdFile' => $this->mdFile,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'createdAtHuman' => $this->createdAt?->diffForHumans(),
        ];
    }
}
