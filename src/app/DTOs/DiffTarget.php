<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DiffTarget
{
    public const EMPTY_TREE_HASH = '4b825dc642cb6eb9a060e54bf8d69288fbee4904';

    public const WORKING_CONTEXT = 'working';

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

    /** Build from raw ref strings - null $to means working directory */
    public static function fromRefs(string $from, ?string $to): self
    {
        return $to === null
            ? self::workingDirectory()
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

    public function isImmutable(): bool
    {
        return $this->to !== null;
    }

    public function contextKey(): string
    {
        return $this->to === null ? self::WORKING_CONTEXT : $this->from.'..'.$this->to;
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

    public function cacheTtlHours(): int
    {
        return $this->isImmutable() ? 720 : (int) config('rfa.cache_ttl_hours', 24);
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
