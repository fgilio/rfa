<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenProjectFromPathAction;
use Native\Desktop\Events\App\OpenedFromURL;
use Native\Desktop\Facades\Window;

final readonly class HandleDeepLink
{
    public function __construct(
        private OpenProjectFromPathAction $openProject,
    ) {}

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

        $project = $this->openProject->handle($path);

        if ($project) {
            Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
        }
    }
}
