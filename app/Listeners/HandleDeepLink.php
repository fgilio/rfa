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

            Context::add('rfa.mode', is_string($query['mode'] ?? null) ? $query['mode'] : null);
            Context::add('rfa.path_hash', hash('xxh128', $path));

            app(RecordRuntimeDiagnosticAction::class)->handle('deeplink.received', [
                'mode' => is_string($query['mode'] ?? null) ? $query['mode'] : null,
                'path_hash' => hash('xxh128', $path),
            ]);

            $project = app(OpenProjectFromPathAction::class)->handle($path);

            if (! $project) {
                // The action swallows unexpected registration failures and marks
                // them via Context (rfa.reason = project_registration_failed);
                // only a genuinely non-project path is a rejection.
                if (Context::get('rfa.reason') === 'project_registration_failed') {
                    $outcome = 'error';
                } else {
                    $outcome = 'rejected';
                    Context::add('rfa.reason', 'not_a_project');
                }

                return;
            }

            // Fail open on junk mode values: anything that isn't 'context' lands
            // on review-page rather than failing the whole open.
            $routeName = (($query['mode'] ?? null) === 'context') ? 'context-page' : 'review-page';

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
