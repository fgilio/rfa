<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenTerminalRequestAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\App\OpenedFromURL;
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
            // The helper emits the inbox filename stem here so this open and
            // the inbox copy of it resolve to the same request. Older
            // path-only links carry no id and are opened unconditionally.
            $requestId = OpenTerminalRequestAction::normalizeRequestId(
                is_string($query['id'] ?? null) ? $query['id'] : null,
            );
            $pathHash = hash('xxh128', $path);
            $routeName = OpenTerminalRequestAction::routeName($mode);

            Context::add('rfa.mode', $mode);
            Context::add('rfa.path_hash', $pathHash);
            Context::add('rfa.request_id', $requestId);
            Context::add('rfa.route', $routeName);

            app(RecordRuntimeDiagnosticAction::class)->handle('deeplink.received', [
                'mode' => $mode,
                'path_hash' => $pathHash,
                'request_id' => $requestId,
            ]);

            $project = app(OpenTerminalRequestAction::class)->handle($path, $mode, $requestId);

            if (! $project) {
                $outcome = OpenTerminalRequestAction::outcomeForNullProject();

                Context::addIf('rfa.reason', 'not_a_project');

                return;
            }

            Context::add('rfa.project_id', $project->id);
            Context::add('rfa.project_slug', $project->slug);

            app(RecordRuntimeDiagnosticAction::class)->handle('deeplink.opened', [
                'route' => $routeName,
                'project_id' => $project->id,
                'project_slug' => $project->slug,
            ]);
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
