<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordOpcacheWarmManifestAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a page or a Livewire update is served, records the scripts it loaded
 * so the next launch can warm opcache before its own first page request.
 *
 * Runs in the terminate phase, after the response has left the process, so
 * the request itself never waits on the manifest write.
 */
final class RecordOpcacheWarmManifest
{
    public function __construct(
        private readonly RecordOpcacheWarmManifestAction $recorder,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! self::isRecordable($request, $response)) {
            return;
        }

        $this->recorder->handle();
    }

    /**
     * A successful HTML page load or Livewire update. Together they cover the
     * page shell, the lazy diff components, and the actions a launch reaches
     * for first. Asset, API, and diagnostic requests add nothing worth warming.
     */
    public static function isRecordable(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($request->headers->has('X-Livewire')) {
            return $request->isMethod('POST');
        }

        return $request->isMethod('GET')
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
