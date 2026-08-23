<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DiffLoadOutcome;

/**
 * The cache contract for one loaded file diff: an explicit outcome plus the
 * rendered payload, stamped with the format version that wrote it.
 *
 * Livewire and Blade consume {@see self::toArray()}, never the object, so the
 * component's state stays a plain array.
 */
final readonly class LoadedDiff
{
    /**
     * Stored format version. Bump it whenever the array {@see self::toArray()}
     * writes changes shape: {@see self::fromCache()} reads every other version
     * as a miss, so entries written by an older build are recomputed.
     */
    public const VERSION = 1;

    /** Envelope keys {@see self::toArray()} adds around the file payload. */
    private const ENVELOPE_KEYS = ['cacheVersion', 'outcome', 'syntaxStyles', 'newFileLineCount', 'syntaxHighlighter'];

    /**
     * @param  array<string, mixed>  $file  A FileDiff::toArray() payload, or its empty equivalent.
     */
    private function __construct(
        public DiffLoadOutcome $outcome,
        public array $file,
        public string $syntaxStyles,
        public ?int $newFileLineCount,
        public string $syntaxHighlighter,
    ) {}

    public static function loaded(FileDiff $fileDiff, string $syntaxStyles, ?int $newFileLineCount, string $syntaxHighlighter): self
    {
        return new self(DiffLoadOutcome::Loaded, $fileDiff->toArray(), $syntaxStyles, $newFileLineCount, $syntaxHighlighter);
    }

    /** Git produced no output for the file. */
    public static function empty(string $path): self
    {
        return self::skipped(DiffLoadOutcome::Empty, $path);
    }

    public static function tooLarge(string $path): self
    {
        return self::skipped(DiffLoadOutcome::TooLarge, $path);
    }

    public static function unparsable(string $path): self
    {
        return self::skipped(DiffLoadOutcome::Unparsable, $path);
    }

    public static function transientError(string $path): self
    {
        return self::skipped(DiffLoadOutcome::TransientError, $path);
    }

    /** Rebuild from a cached or in-flight array, or null when the entry is not a current envelope. */
    public static function fromCache(mixed $cached): ?self
    {
        if (! is_array($cached) || ($cached['cacheVersion'] ?? null) !== self::VERSION) {
            return null;
        }

        $outcome = is_string($cached['outcome'] ?? null) ? DiffLoadOutcome::tryFrom($cached['outcome']) : null;

        if ($outcome === null) {
            return null;
        }

        $file = collect($cached)->except(self::ENVELOPE_KEYS)->all();

        return new self(
            $outcome,
            $file,
            is_string($cached['syntaxStyles'] ?? null) ? $cached['syntaxStyles'] : '',
            is_int($cached['newFileLineCount'] ?? null) ? $cached['newFileLineCount'] : null,
            is_string($cached['syntaxHighlighter'] ?? null) ? $cached['syntaxHighlighter'] : 'none',
        );
    }

    /**
     * The same diff re-read at full context. Gap expansion replaces the hunks
     * and appends the styles the wider read introduced.
     *
     * @param  array<int, array<string, mixed>>  $hunks
     */
    public function withExpandedHunks(array $hunks, string $additionalSyntaxStyles): self
    {
        return new self(
            $this->outcome,
            [...$this->file, 'hunks' => $hunks],
            $this->syntaxStyles.$additionalSyntaxStyles,
            $this->newFileLineCount,
            $this->syntaxHighlighter,
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function hunks(): array
    {
        $hunks = $this->file['hunks'] ?? [];

        return is_array($hunks) ? $hunks : [];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->file,
            'cacheVersion' => self::VERSION,
            'outcome' => $this->outcome->value,
            'syntaxStyles' => $this->syntaxStyles,
            'newFileLineCount' => $this->newFileLineCount,
            'syntaxHighlighter' => $this->syntaxHighlighter,
        ];
    }

    private static function skipped(DiffLoadOutcome $outcome, string $path): self
    {
        return new self($outcome, FileDiff::emptyArray($path, 'modified'), '', null, 'none');
    }
}
