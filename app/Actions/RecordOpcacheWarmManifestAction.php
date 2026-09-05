<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\OpcacheWarmService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records the scripts a finished page load compiled so the next launch can
 * warm them before its first request.
 *
 * Runs after the response is sent. The common case, a manifest that already
 * lists everything, does no work and stays silent: the canonical event marks
 * the launches that actually taught the manifest something new.
 */
final readonly class RecordOpcacheWarmManifestAction
{
    public function __construct(
        private OpcacheWarmService $warmer,
    ) {}

    public function handle(): void
    {
        $startedAt = microtime(true);

        try {
            $result = $this->warmer->record();
        } catch (Throwable $e) {
            Context::flush();
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'opcache_manifest_record_failed');
            Context::add('rfa.outcome', 'error');
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('opcache.manifest.recorded');

            throw $e;
        }

        if (! $result['written']) {
            return;
        }

        Context::flush();
        Context::add('rfa.script_count', $result['total']);
        Context::add('rfa.added_count', $result['added']);
        Context::add('rfa.outcome', 'completed');
        Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

        Log::info('opcache.manifest.recorded');
    }
}
