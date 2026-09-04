<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\LastViewMode;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Native\Desktop\Facades\Window;

/**
 * Open the path a `./rfa` invocation asked for, once.
 *
 * One invocation announces itself twice: it writes an inbox file (cold-start
 * safety, drained during boot) and opens an `rfa://open` deep link (which
 * launches or wakes the app). Both transports carry the same request id — the
 * inbox filename stem — so whichever arrives first claims it and the other
 * one stands down instead of registering, inspecting and navigating a second
 * time.
 */
final readonly class OpenTerminalRequestAction
{
    private const string CLAIM_CACHE_PREFIX = 'terminal-open-request:';

    /**
     * How long a claim is remembered. Long enough to cover a cold start, where
     * boot drains the inbox and the deep link only arrives once Electron has
     * registered the protocol handler; short enough that claims from earlier
     * sessions never pile up.
     */
    private const int CLAIM_MINUTES = 10;

    /** Inbox filename stems, and therefore request ids, look like `<epoch>-<pid>`. */
    private const string REQUEST_ID_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

    public function __construct(
        private OpenPathForReviewAction $openPath,
    ) {}

    /**
     * Claim `$requestId`, open `$path`, and navigate the main window.
     *
     * Returns null when another transport already claimed this request, or
     * when the path could not be opened for review. The reason lands in
     * `rfa.reason` for the calling owner's canonical event.
     */
    public function handle(string $path, ?string $mode = null, ?string $requestId = null): ?Project
    {
        if (! $this->claim($requestId)) {
            Context::add('rfa.reason', 'request_already_claimed');

            return null;
        }

        $target = $this->openPath->handle($path);

        if ($target === null) {
            return null;
        }

        $routeName = self::routeName($mode);
        $routeParameters = ['slug' => $target['project']->slug];

        if ($routeName === 'review-page' && $target['filePath'] !== null) {
            $routeParameters['file'] = $target['filePath'];
        }

        Window::get('main')->url(route($routeName, $routeParameters));

        return $target['project'];
    }

    /**
     * Map a null return from handle() to a canonical outcome.
     *
     * A request the other transport already claimed stood down rather
     * than being turned away, so it reads as skipped instead
     * of rejected.
     */
    public static function outcomeForNullProject(): string
    {
        return match (Context::get('rfa.reason')) {
            'request_already_claimed' => 'skipped',
            'file_workspace_failed', 'project_registration_failed' => 'error',
            default => 'rejected',
        };
    }

    /**
     * Fail open on junk mode values: anything that isn't `context` lands on
     * the review page rather than failing the whole open.
     */
    public static function routeName(?string $mode): string
    {
        return LastViewMode::tryFrom($mode ?? '') === LastViewMode::Context
            ? 'context-page'
            : 'review-page';
    }

    /**
     * The request id an inbox file carries: its filename stem, which the
     * terminal helper also emits as the deep link's `id` query parameter.
     */
    public static function inboxRequestId(string $file): ?string
    {
        return self::normalizeRequestId(pathinfo($file, PATHINFO_FILENAME));
    }

    /**
     * Parse the two-line inbox file format: `<path>\n<mode>`. The path
     * lives on line 1, the optional mode on line 2. Splitting on newline
     * first and trimming each line independently keeps a trailing newline
     * (added by every `printf` / `echo`) from silently dropping the mode.
     * Single-line legacy files leave the mode null.
     *
     * @return array{path: string, mode: ?string}
     */
    public static function parseInboxContents(string $contents): array
    {
        $lines = preg_split('/\r?\n/', $contents) ?: [];
        $mode = isset($lines[1]) ? trim($lines[1]) : '';

        return [
            'path' => isset($lines[0]) ? trim($lines[0]) : '',
            'mode' => $mode === '' ? null : $mode,
        ];
    }

    /**
     * Ids reach us from a deep link URL and from a filename, so keep them to
     * the shape the helper emits before they become a cache key. Anything
     * else is treated as an unidentified request.
     */
    public static function normalizeRequestId(?string $requestId): ?string
    {
        if ($requestId === null || preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }

    /**
     * An atomic claim: `Cache::add()` writes only when the key is absent, so
     * exactly one of two concurrent consumers wins. A request without an id
     * is a compatibility input — a path-only deep link from an older helper,
     * or a legacy inbox filename — and has no shared identity to deduplicate
     * against, so it is always allowed through.
     */
    private function claim(?string $requestId): bool
    {
        $claimId = self::normalizeRequestId($requestId);

        if ($claimId === null) {
            // An id that was supplied but rejected means the helper and this
            // pattern have drifted apart, which silently turns deduplication
            // off. Say so in the event rather than opening twice in silence.
            if ($requestId !== null) {
                Context::add('rfa.unrecognized_request_id', $requestId);
            }

            return true;
        }

        return Cache::add(
            self::CLAIM_CACHE_PREFIX.$claimId,
            true,
            now()->addMinutes(self::CLAIM_MINUTES),
        );
    }
}
