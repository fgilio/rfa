<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenProjectFromPathAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;
use Throwable;

final readonly class HandleDeepLink
{
    public function handle(OpenedFromURL $event): void
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';

        try {
            $parsed = parse_url($event->url);

            if (! is_array($parsed) || ($parsed['scheme'] ?? '') !== 'rfa' || ($parsed['host'] ?? '') !== 'open') {
                $outcome = 'rejected';
                Context::add('rfa.reason', 'unsupported_url');

                return;
            }

            parse_str($parsed['query'] ?? '', $query);
            $path = $query['path'] ?? null;

            if (! is_string($path) || $path === '') {
                $outcome = 'rejected';
                Context::add('rfa.reason', 'missing_path');

                return;
            }

            $mode = is_string($query['mode'] ?? null) ? $query['mode'] : null;
            $pathHash = hash('xxh128', $path);

            Context::add('rfa.mode', $mode);
            Context::add('rfa.path_hash', $pathHash);

            app(RecordRuntimeDiagnosticAction::class)->handle('deeplink.received', [
                'mode' => $mode,
                'path_hash' => $pathHash,
            ]);

            $project = app(OpenProjectFromPathAction::class)->handle($path);

            if (! $project) {
                // The action marks why it returned null via Context: an
                // unexpected registration failure is an error, everything
                // else (missing path, not a repo) is a rejection.
                $outcome = Context::get('rfa.reason') === 'project_registration_failed'
                    ? 'error'
                    : 'rejected';

                Context::addIf('rfa.reason', 'not_a_project');

                return;
            }

            // Fail open on junk mode values: anything that isn't 'context' lands
            // on review-page rather than failing the whole open.
            $routeName = ($mode === 'context') ? 'context-page' : 'review-page';

            Context::add('rfa.route', $routeName);
            Context::add('rfa.project_id', $project->id);
            Context::add('rfa.project_slug', $project->slug);

            app(RecordRuntimeDiagnosticAction::class)->handle('deeplink.opened', [
                'route' => $routeName,
                'project_id' => $project->id,
                'project_slug' => $project->slug,
            ]);

            Window::get('main')->url(route($routeName, ['slug' => $project->slug]));
        } catch (Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'deeplink_open_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('deeplink.opened');
        }
    }
}
