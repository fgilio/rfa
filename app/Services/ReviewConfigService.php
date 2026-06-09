<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\ReviewConfig;

class ReviewConfigService
{
    /** @var list<string> */
    private const MOVED_LINE_MODES = ['plain', 'blocks', 'zebra', 'dimmed-zebra'];

    /** @var array<string, mixed> */
    private const FALLBACKS = [
        'diffMaxBytes' => 512_000,
        'sourceMaxBytes' => 1_048_576,
        'cacheTtlHours' => 24,
        'defaultContextLines' => 3,
        'movedLineDetection' => false,
        'movedLineMode' => 'zebra',
    ];

    private ?ReviewConfig $memoizedDefaults = null;

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
        if ($userSettings === [] && $repoSettings === [] && $runtimeOverrides === [] && $this->memoizedDefaults !== null) {
            return $this->memoizedDefaults;
        }

        $settings = array_replace(
            $this->defaults(),
            $this->normalize($userSettings),
            $this->normalize($repoSettings),
            $this->normalize($runtimeOverrides),
        );

        $movedLineDetection = $this->boolean($settings, 'movedLineDetection', self::FALLBACKS['movedLineDetection']);

        $config = new ReviewConfig(
            diffMaxBytes: $this->positiveInt($settings, 'diffMaxBytes', self::FALLBACKS['diffMaxBytes']),
            sourceMaxBytes: $this->positiveInt($settings, 'sourceMaxBytes', self::FALLBACKS['sourceMaxBytes']),
            cacheTtlHours: $this->positiveInt($settings, 'cacheTtlHours', self::FALLBACKS['cacheTtlHours']),
            defaultContextLines: $this->nonNegativeInt($settings, 'defaultContextLines', self::FALLBACKS['defaultContextLines']),
            movedLineDetection: $movedLineDetection,
            movedLineMode: $this->movedLineMode((string) $settings['movedLineMode'], self::FALLBACKS['movedLineMode']),
        );

        if ($userSettings === [] && $repoSettings === [] && $runtimeOverrides === []) {
            $this->memoizedDefaults = $config;
        }

        return $config;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'diffMaxBytes' => config('rfa.diff_max_bytes', self::FALLBACKS['diffMaxBytes']),
            'sourceMaxBytes' => config('rfa.source_max_bytes', self::FALLBACKS['sourceMaxBytes']),
            'cacheTtlHours' => config('rfa.cache_ttl_hours', self::FALLBACKS['cacheTtlHours']),
            'defaultContextLines' => config('rfa.default_context_lines', self::FALLBACKS['defaultContextLines']),
            'movedLineDetection' => config('rfa.moved_lines.enabled', self::FALLBACKS['movedLineDetection']),
            'movedLineMode' => config('rfa.moved_lines.mode', self::FALLBACKS['movedLineMode']),
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
    private function positiveInt(array $settings, string $key, int $fallback): int
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 1 ? $value : $fallback;
    }

    /** @param array<string, mixed> $settings */
    private function nonNegativeInt(array $settings, string $key, int $fallback): int
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_INT);

        return is_int($value) && $value >= 0 ? $value : $fallback;
    }

    private function movedLineMode(string $mode, string $fallback): string
    {
        return in_array($mode, self::MOVED_LINE_MODES, true) ? $mode : $fallback;
    }

    /** @param array<string, mixed> $settings */
    private function boolean(array $settings, string $key, bool $fallback): bool
    {
        $value = filter_var($settings[$key] ?? null, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return is_bool($value) ? $value : $fallback;
    }
}
