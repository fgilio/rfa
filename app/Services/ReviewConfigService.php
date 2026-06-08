<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReviewConfig;
use InvalidArgumentException;

class ReviewConfigService
{
    /** @var list<string> */
    private const MOVED_LINE_MODES = ['plain', 'blocks', 'zebra', 'dimmed-zebra'];

    /**
     * Resolve effective review config.
     *
     * Precedence is application defaults, user settings, repo settings, then
     * runtime overrides. Callers can pass empty arrays for layers RFA does not
     * persist yet.
     *
     * @param  array<string, mixed>  $userSettings
     * @param  array<string, mixed>  $repoSettings
     * @param  array<string, mixed>  $runtimeOverrides
     */
    public function resolve(array $userSettings = [], array $repoSettings = [], array $runtimeOverrides = []): ReviewConfig
    {
        $settings = array_replace(
            $this->defaults(),
            $this->normalize($userSettings),
            $this->normalize($repoSettings),
            $this->normalize($runtimeOverrides),
        );

        return new ReviewConfig(
            diffMaxBytes: $this->positiveInt($settings, 'diffMaxBytes'),
            sourceMaxBytes: $this->positiveInt($settings, 'sourceMaxBytes'),
            cacheTtlHours: $this->positiveInt($settings, 'cacheTtlHours'),
            defaultContextLines: $this->nonNegativeInt($settings, 'defaultContextLines'),
            movedLineDetection: $this->boolean($settings, 'movedLineDetection'),
            movedLineMode: $this->movedLineMode((string) $settings['movedLineMode']),
        );
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'diffMaxBytes' => (int) config('rfa.diff_max_bytes', 512_000),
            'sourceMaxBytes' => (int) config('rfa.source_max_bytes', 1_048_576),
            'cacheTtlHours' => (int) config('rfa.cache_ttl_hours', 24),
            'defaultContextLines' => (int) config('rfa.default_context_lines', 3),
            'movedLineDetection' => (bool) config('rfa.moved_lines.enabled', false),
            'movedLineMode' => (string) config('rfa.moved_lines.mode', 'zebra'),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function normalize(array $settings): array
    {
        $aliases = [
            'diff_max_bytes' => 'diffMaxBytes',
            'source_max_bytes' => 'sourceMaxBytes',
            'cache_ttl_hours' => 'cacheTtlHours',
            'default_context_lines' => 'defaultContextLines',
            'moved_line_detection' => 'movedLineDetection',
            'moved_line_mode' => 'movedLineMode',
        ];

        return collect($settings)
            ->mapWithKeys(fn (mixed $value, string $key): array => [$aliases[$key] ?? $key => $value])
            ->only([
                'diffMaxBytes',
                'sourceMaxBytes',
                'cacheTtlHours',
                'defaultContextLines',
                'movedLineDetection',
                'movedLineMode',
            ])
            ->all();
    }

    /** @param array<string, mixed> $settings */
    private function positiveInt(array $settings, string $key): int
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Review config [{$key}] must be a positive integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $settings */
    private function nonNegativeInt(array $settings, string $key): int
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Review config [{$key}] must be zero or greater.");
        }

        return $value;
    }

    private function movedLineMode(string $mode): string
    {
        if (! in_array($mode, self::MOVED_LINE_MODES, true)) {
            throw new InvalidArgumentException('Review config [movedLineMode] is invalid.');
        }

        return $mode;
    }

    /** @param array<string, mixed> $settings */
    private function boolean(array $settings, string $key): bool
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if (! is_bool($value)) {
            throw new InvalidArgumentException("Review config [{$key}] must be a boolean.");
        }

        return $value;
    }
}
