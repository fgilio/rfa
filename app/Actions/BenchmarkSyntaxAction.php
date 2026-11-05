<?php

declare(strict_types=1);

namespace App\Actions;

use App\Console\Benchmark\PerfBenchmarkStatistics;
use App\DTOs\DiffLine;
use App\DTOs\Hunk;
use App\Enums\LineType;
use App\Services\SyntaxHighlightService;
use Phiki\Phiki;
use Phiki\TextMate\Engines\PcreEngine;
use RuntimeException;

final readonly class BenchmarkSyntaxAction
{
    /**
     * @return array{actual_highlighter: string, median_ms: float, median_peak_mb: float, rounds_ms: list<float>, rounds_peak_mb: list<float>}
     */
    public function handle(string $code, string $file, string $backend, int $rounds, int $warmupRounds): array
    {
        $codeLines = explode("\n", str_replace("\r\n", "\n", $code));
        $lines = collect($codeLines)->map(fn (string $line, int $index): DiffLine => new DiffLine(LineType::Context, $line, $index + 1, $index + 1))->all();
        $hunks = [new Hunk('', 1, count($lines), 1, count($lines), $lines)];

        $milliseconds = [];
        $peakMegabytes = [];
        $actual = 'none';
        for ($round = -$warmupRounds; $round < $rounds; $round++) {
            gc_collect_cycles();
            memory_reset_peak_usage();
            $startingMemory = memory_get_usage();
            $startedAt = hrtime(true);
            $phiki = new Phiki;
            if ($backend === 'phiki-pcre') {
                $phiki->engine(new PcreEngine);
            }
            $service = new SyntaxHighlightService(preferPhiki: $backend !== 'auto', phiki: $phiki);
            $highlighted = $service->highlightHunks($hunks, $file);
            $duration = (hrtime(true) - $startedAt) / 1_000_000;
            $peak = max(0, memory_get_peak_usage() - $startingMemory) / 1024 / 1024;
            $actual = $service->lastHighlighter();
            foreach ($highlighted[0]->lines as $line) {
                if ($line->highlightedContent === null || html_entity_decode(strip_tags($line->highlightedContent), ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $line->content) {
                    throw new RuntimeException("Highlighting lost source content: {$file} ({$backend}).");
                }
            }
            if ($actual === 'none' || ($backend !== 'auto' && $actual !== 'phiki')) {
                throw new RuntimeException("The requested highlighter did not run: {$file} ({$backend}).");
            }
            unset($highlighted, $service, $phiki);
            if ($round >= 0) {
                $milliseconds[] = $duration;
                $peakMegabytes[] = $peak;
            }
        }

        return [
            'actual_highlighter' => $actual,
            'median_ms' => PerfBenchmarkStatistics::median($milliseconds),
            'median_peak_mb' => PerfBenchmarkStatistics::median($peakMegabytes),
            'rounds_ms' => $milliseconds,
            'rounds_peak_mb' => $peakMegabytes,
        ];
    }
}
