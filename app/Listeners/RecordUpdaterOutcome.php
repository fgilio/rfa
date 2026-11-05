<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RecordRuntimeDiagnosticAction;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\AutoUpdater\Error as UpdateError;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

/**
 * Records the outcome of each auto-updater check.
 *
 * The update banner owns updater state: it receives the same events through
 * its `native:` listeners and drives `UpdaterStateAction`. This listener only
 * writes the canonical event and a diagnostics breadcrumb, so packaged builds
 * (which keep `info` out of the log) still leave a trace of every outcome.
 */
final readonly class RecordUpdaterOutcome
{
    public function __construct(
        private RecordRuntimeDiagnosticAction $diagnostics,
    ) {}

    public function handleUpdateAvailable(UpdateAvailable $event): void
    {
        $startedAt = $this->begin();
        Context::add('rfa.update_version', $event->version);

        $this->diagnostics->handle('updater.available', ['version' => $event->version]);

        $this->finish($startedAt, 'completed');
        Log::info('updater.available');
    }

    public function handleUpdateNotAvailable(UpdateNotAvailable $event): void
    {
        $startedAt = $this->begin();
        Context::add('rfa.update_version', $event->version);

        $this->diagnostics->handle('updater.not_available', ['version' => $event->version]);

        $this->finish($startedAt, 'completed');
        Log::info('updater.not_available');
    }

    public function handleUpdateDownloaded(UpdateDownloaded $event): void
    {
        $startedAt = $this->begin();
        Context::add('rfa.update_version', $event->version);

        $this->diagnostics->handle('updater.downloaded', ['version' => $event->version]);

        $this->finish($startedAt, 'completed');
        Log::info('updater.downloaded');
    }

    public function handleUpdateError(UpdateError $event): void
    {
        $startedAt = $this->begin();
        $message = LogSanitizer::summary($event->message);

        Context::add('rfa.reason', 'updater_error');
        Context::add('rfa.error_name', $event->name);

        // Packaged builds log at `warning`, so this is the entry that carries
        // the failure detail there. The canonical info event follows below.
        Log::error('updater.failed', [
            'reason' => 'updater_error',
            'error_name' => $event->name,
            'message' => $message,
            'stack' => LogSanitizer::summary($event->stack, 500),
        ]);

        $this->diagnostics->handle('updater.failed', [
            'error_name' => $event->name,
            'message' => $message,
        ]);

        $this->finish($startedAt, 'error');
        Log::info('updater.failed');
    }

    private function begin(): float
    {
        Context::flush();

        return microtime(true);
    }

    /**
     * Electron reports each outcome on its own, with no start time this
     * process can trust, so the duration covers the listener callback.
     */
    private function finish(float $startedAt, string $outcome): void
    {
        Context::add('rfa.outcome', $outcome);
        Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));
    }
}
