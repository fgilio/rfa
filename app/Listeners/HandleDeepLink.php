<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenProjectFromPathAction;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;

final readonly class HandleDeepLink
{
    public function handle(OpenedFromURL $event): void
    {
        $parsed = parse_url($event->url);

        if (! is_array($parsed) || ($parsed['scheme'] ?? '') !== 'rfa' || ($parsed['host'] ?? '') !== 'open') {
            return;
        }

        parse_str($parsed['query'] ?? '', $query);
        $path = $query['path'] ?? null;

        if (! is_string($path) || $path === '') {
            return;
        }

        $project = app(OpenProjectFromPathAction::class)->handle($path);

        if (! $project) {
            return;
        }

        // Fail open on junk mode values: anything that isn't 'context' lands
        // on review-page rather than failing the whole open.
        $routeName = (($query['mode'] ?? null) === 'context') ? 'context-page' : 'review-page';

        Window::get('main')->url(route($routeName, ['slug' => $project->slug]));
    }
}
