<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\GitRef;

final readonly class DiffTarget
{
    public const EMPTY_TREE_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    /** Cache lifetime for an immutable target's diffs (30 days in hours). */
    private const IMMUTABLE_TTL_HOURS = 720;

    private function __construct(
        private string $from,
        private ?string $to,
    ) {}

    public static function workingDirectory(): self
    {
        return new self(from: 'HEAD', to: null);
    }

    public static function commit(string $hash, ?string $parentHash = null): self
    {
        return self::range($parentHash ?? self::EMPTY_TREE_HASH, $hash);
    }

    public static function range(string $from, string $to): self
    {
        return new self(from: $from, to: $to);
    }

    public static function rangeToWorking(string $from): self
    {
        return new self(from: $from, to: null);
    }

    /** Build from raw ref strings - null $to means working directory */
    public static function fromRefs(string $from, ?string $to): self
    {
        return $to === null
            ? self::rangeToWorking($from)
            : new self(from: $from, to: $to);
    }

    public function from(): string
    {
        return $this->from;
    }

    public function to(): ?string
    {
        return $this->to;
    }

    public function isWorkingDirectory(): bool
    {
        return $this->to === null;
    }

    /**
     * Whether this target compares two fixed refs. A pinned to-ref never
     * changes, so the resulting diff is safe to cache indefinitely.
     */
    public function isImmutable(): bool
    {
        return ! $this->isWorkingDirectory();
    }

    public function contextKey(): string
    {
        return $this->from.'..'.($this->to ?? GitRef::Working->value);
    }

    /** @return list<string> Git diff command prefix args */
    public function toDiffArgs(): array
    {
        $args = ['diff', $this->from];

        if ($this->to !== null) {
            $args[] = $this->to;
        }

        return $args;
    }

    /**
     * Cache lifetime in hours for this target's diffs. An immutable target
     * caches for IMMUTABLE_TTL_HOURS since its diff never changes; a
     * working-directory target uses the effective TTL the caller resolved from
     * ReviewConfig, so the stored entry and the pipeline that built it agree.
     */
    public function cacheTtlHours(int $workingDirectoryTtlHours): int
    {
        return $this->isImmutable() ? self::IMMUTABLE_TTL_HOURS : $workingDirectoryTtlHours;
    }

    /** @return array{from: string, to: ?string} */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
        ];
    }
}
