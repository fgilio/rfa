<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\OpcacheWarmService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pre-compiles the scripts a page load needs into opcache shared memory.
 *
 * Called by the Electron main process right after the PHP server starts,
 * before the window and its first page request exist.
 */
final readonly class WarmOpcacheAction
{
    public function __construct(
        private OpcacheWarmService $warmer,
    ) {}

    /** @return array{available: bool, compiled: int, cached: int, missing: int, failed: int} */
    public function handle(): array
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';
        $result = ['available' => false, 'compiled' => 0, 'cached' => 0, 'missing' => 0, 'failed' => 0];

        try {
            $result = $this->warmer->warm();

            if (! $result['available']) {
                $outcome = 'skipped';
                Context::add('rfa.reason', 'opcache_unavailable');
            } elseif ($result['compiled'] === 0 && $result['cached'] === 0) {
                $outcome = 'skipped';
                Context::add('rfa.reason', 'empty_manifest');
            }
        } catch (Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'opcache_warm_failed');

            throw $e;
        } finally {
            Context::add('rfa.compiled_count', $result['compiled']);
            Context::add('rfa.cached_count', $result['cached']);
            Context::add('rfa.missing_count', $result['missing']);
            Context::add('rfa.failed_count', $result['failed']);
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('opcache.warmed');
        }

        return $result;
    }
}
