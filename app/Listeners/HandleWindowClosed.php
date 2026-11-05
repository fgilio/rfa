<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\RecordRuntimeDiagnosticAction;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\Windows\WindowClosed;
use Native\Desktop\Facades\App;
use Throwable;

/**
 * Quits rfa when its main window closes.
 *
 * Electron keeps a macOS app alive after its last window closes, which would
 * leave rfa running with no window for deep links to navigate.
 */
final readonly class HandleWindowClosed
{
    public function handle(WindowClosed $event): void
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'skipped';
        $isMainWindow = $event->id === 'main';

        Context::add('rfa.window_id', $event->id);
        Context::add('rfa.quit_requested', $isMainWindow);

        try {
            app(RecordRuntimeDiagnosticAction::class)->handle('window.closed', [
                'id' => $event->id,
            ]);

            if ($isMainWindow) {
                UnregisterNativeGlobalShortcuts::unregister();
                App::quit();

                $outcome = 'completed';
            }
        } catch (Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'window_close_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('window.closed');
        }
    }
}
