<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use InvalidArgumentException;

/**
 * The validated input of `rfa:benchmark-perf`.
 *
 * Parsing happens once, before the first child process starts, so a mistyped
 * sample count or a threshold that cannot fail costs a message rather than a
 * benchmark run and a report that reads as a clean pass.
 */
final readonly class BenchmarkOptions
{
    /**
     * @param  list<string>  $only
     * @param  array<string, MetricThreshold>  $thresholds  keyed by the metric each one gates
     */
    private function __construct(
        public bool $child,
        public bool $json,
        public ?string $snapshotPath,
        public ?string $comparePath,
        public int $samples,
        public int $warmupSamples,
        public int $rounds,
        public int $warmupRounds,
        public array $only,
        public array $thresholds,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $availableScenarios
     *
     * @throws InvalidArgumentException
     */
    public static function fromOptions(array $options, array $availableScenarios): self
    {
        $parsed = new self(
            child: (bool) ($options['child'] ?? false),
            json: (bool) ($options['json'] ?? false),
            snapshotPath: self::path($options, 'snapshot'),
            comparePath: self::path($options, 'compare'),
            samples: self::wholeNumber($options, 'samples', minimum: 1),
            warmupSamples: self::wholeNumber($options, 'warmup-samples', minimum: 0),
            rounds: self::wholeNumber($options, 'rounds', minimum: 1),
            warmupRounds: self::wholeNumber($options, 'warmup-rounds', minimum: 0),
            only: self::scenarios($options, $availableScenarios),
            thresholds: self::thresholds($options),
        );

        $parsed->assertModesAgree();

        return $parsed;
    }

    /** @return list<string> */
    public function childArguments(): array
    {
        return [
            '--child',
            '--json',
            '--rounds='.$this->rounds,
            '--warmup-rounds='.$this->warmupRounds,
            ...array_map(fn (string $scenario): string => '--only='.$scenario, $this->only),
        ];
    }

    /** @return array<string, int|float> */
    public function reportConfig(): array
    {
        return [
            'samples' => $this->samples,
            'warmup_samples' => $this->warmupSamples,
            'rounds' => $this->rounds,
            'warmup_rounds' => $this->warmupRounds,
            'max_regression' => $this->thresholds['median_ms']->maxRegression,
            'max_memory_regression' => $this->thresholds['median_peak_mb']->maxRegression,
            'max_retained_memory_regression' => $this->thresholds['median_retained_mb']->maxRegression,
        ];
    }

    /**
     * The regression policy per metric, so a metric added to the report gates
     * the build by adding one row here rather than a pair of scalar fields.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, MetricThreshold>
     */
    private static function thresholds(array $options): array
    {
        return [
            'median_ms' => new MetricThreshold(
                self::threshold($options, 'max-regression'),
                self::threshold($options, 'min-absolute-ms'),
            ),
            'median_peak_mb' => new MetricThreshold(
                self::threshold($options, 'max-memory-regression'),
                self::threshold($options, 'min-absolute-memory-mb'),
            ),
            'median_retained_mb' => new MetricThreshold(
                self::threshold($options, 'max-retained-memory-regression'),
                self::threshold($options, 'min-absolute-retained-memory-mb'),
            ),
        ];
    }

    private function assertModesAgree(): void
    {
        throw_if(
            $this->child && $this->snapshotPath !== null,
            InvalidArgumentException::class,
            'A --child run measures one sample and cannot write a snapshot. Drop --snapshot or --child.',
        );

        throw_if(
            $this->child && $this->comparePath !== null,
            InvalidArgumentException::class,
            'A --child run measures one sample and cannot compare against a snapshot. Drop --compare or --child.',
        );

        throw_if(
            $this->json && $this->comparePath !== null,
            InvalidArgumentException::class,
            'A --compare run reports through its table and exit code, so --json has no output to produce. Drop --json or --compare.',
        );

        throw_if(
            $this->snapshotPath !== null && $this->snapshotPath === $this->comparePath,
            InvalidArgumentException::class,
            "The --snapshot and --compare options both point at {$this->snapshotPath}. Comparing a run against the snapshot it just wrote can never fail.",
        );
    }

    /** @param array<string, mixed> $options */
    private static function path(array $options, string $name): ?string
    {
        $value = $options[$name] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        throw_unless(is_string($value), InvalidArgumentException::class, sprintf(
            'The --%s option must be a file path. Received: %s.',
            $name,
            self::describe($value),
        ));

        return $value;
    }

    /** @param array<string, mixed> $options */
    private static function wholeNumber(array $options, string $name, int $minimum): int
    {
        $value = filter_var($options[$name] ?? null, FILTER_VALIDATE_INT);

        throw_if($value === false || $value < $minimum, InvalidArgumentException::class, sprintf(
            'The --%s option must be a whole number of %d or more. Received: %s.',
            $name,
            $minimum,
            self::describe($options[$name] ?? null),
        ));

        return $value;
    }

    /**
     * A negative threshold turns every comparison into a regression, so the
     * benchmark would fail on a run that got faster.
     *
     * @param  array<string, mixed>  $options
     */
    private static function threshold(array $options, string $name): float
    {
        $value = filter_var($options[$name] ?? null, FILTER_VALIDATE_FLOAT);

        throw_if($value === false || $value < 0, InvalidArgumentException::class, sprintf(
            'The --%s option must be a number of 0 or more. Received: %s.',
            $name,
            self::describe($options[$name] ?? null),
        ));

        return $value;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  list<string>  $availableScenarios
     * @return list<string>
     */
    private static function scenarios(array $options, array $availableScenarios): array
    {
        $only = collect((array) ($options['only'] ?? []))
            ->map(fn (mixed $scenario): string => (string) $scenario)
            ->filter()
            ->values();

        $unknown = $only->diff($availableScenarios)->values();

        throw_if($unknown->isNotEmpty(), InvalidArgumentException::class, sprintf(
            'Unknown benchmark scenario [%s]. Available scenarios: %s',
            $unknown->implode(', '),
            implode(', ', $availableScenarios),
        ));

        return $only->all();
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : get_debug_type($value);
    }
}
