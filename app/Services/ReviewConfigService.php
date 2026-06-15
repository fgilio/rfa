<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReviewConfig;

class ReviewConfigService
{
    /** @var list<string> */
    private const MOVED_LINE_MODES = ['plain', 'blocks', 'zebra', 'dimmed-zebra'];

    private ?ReviewConfig $memoized = null;

    /**
     * Resolve the effective review config from config/rfa.php.
     *
     * Each value is coerced and bounds-checked so a malformed config or env
     * entry falls back to its safe default rather than reaching the diff
     * pipeline. The result is memoized for the request.
     */
    public function resolve(): ReviewConfig
    {
        return $this->memoized ??= new ReviewConfig(
            diffMaxBytes: $this->positiveInt(config('rfa.diff_max_bytes'), 512_000),
            sourceMaxBytes: $this->positiveInt(config('rfa.source_max_bytes'), 1_048_576),
            cacheTtlHours: $this->positiveInt(config('rfa.cache_ttl_hours'), 24),
            defaultContextLines: $this->nonNegativeInt(config('rfa.default_context_lines'), 3),
            movedLineDetection: $this->boolean(config('rfa.moved_lines.enabled'), false),
            movedLineMode: $this->movedLineMode(config('rfa.moved_lines.mode'), 'zebra'),
        );
    }

    private function positiveInt(mixed $value, int $fallback): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($int) && $int >= 1 ? $int : $fallback;
    }

    private function nonNegativeInt(mixed $value, int $fallback): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($int) && $int >= 0 ? $int : $fallback;
    }

    private function movedLineMode(mixed $value, string $fallback): string
    {
        return is_string($value) && in_array($value, self::MOVED_LINE_MODES, true) ? $value : $fallback;
    }

    private function boolean(mixed $value, bool $fallback): bool
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($bool) ? $bool : $fallback;
    }
}
