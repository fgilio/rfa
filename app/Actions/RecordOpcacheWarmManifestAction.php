<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\OpcacheWarmService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the scripts a finished request compiled so the next launch can
 * warm them before its first request.
 *
 * Runs after the response is sent, so a failure has nobody left to answer
 * to and is logged instead of thrown. The common case, a manifest that
 * already lists everything, does no work and stays silent: the canonical
 * event marks the requests that taught the manifest something new.
 */
final readonly class RecordOpcacheWarmManifestAction
{
    public function __construct(
        private OpcacheWarmService $warmer,
    ) {}

    public function handle(): void
    {
        $startedAt = microtime(true);
        $result = ['total' => 0, 'added' => 0, 'written' => false];
        $failure = null;

        try {
            $result = $this->warmer->record();
        } catch (Throwable $failure) {
            Log::error('opcache.manifest.record.failed', [
                'reason' => 'opcache_manifest_record_failed',
                'error_class' => $failure::class,
            ]);
        }

        if (! $result['written'] && $failure === null) {
            return;
        }

        Context::flush();
        Context::add('rfa.script_count', $result['total']);
        Context::add('rfa.added_count', $result['added']);

        if ($failure !== null) {
            Context::add('rfa.error_class', $failure::class);
            Context::add('rfa.reason', 'opcache_manifest_record_failed');
        }

        Context::add('rfa.outcome', $failure === null ? 'completed' : 'error');
        Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

        Log::info('opcache.manifest.recorded');
    }
}
