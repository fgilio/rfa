<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\RecordOpcacheWarmManifestAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a request is served, records the scripts it loaded so the next
 * launch can warm opcache before its own first page request.
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
        // Only successful responses teach the manifest: the scripts an error
        // page loads are not worth compiling on every launch. Anything else
        // is self-limiting, since the manifest is only written when it grows.
        if (! $response->isSuccessful()) {
            return;
        }

        $this->recorder->handle();
    }
}
