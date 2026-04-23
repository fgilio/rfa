<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\BuildRemoteUrlAction;
use Native\Desktop\Facades\Shell;

/**
 * Shared Livewire methods for "Open on remote" and "Copy remote link" context
 * menu actions. Consumers call `openRemote($slug, $type, $params)` or
 * `copyRemoteLink(...)` from Blade; the URL is always rebuilt server-side from
 * the project's stored `remote_url`, so we never trust a URL from the DOM.
 */
trait InteractsWithRemoteLinks
{
    /** @param array<string, mixed> $params */
    public function openRemote(string $projectSlug, string $type, array $params = []): void
    {
        $url = app(BuildRemoteUrlAction::class)->handle($projectSlug, $type, $params);

        if ($url !== null) {
            Shell::openExternal($url);
        }
    }

    /** @param array<string, mixed> $params */
    public function copyRemoteLink(string $projectSlug, string $type, array $params = []): void
    {
        $url = app(BuildRemoteUrlAction::class)->handle($projectSlug, $type, $params);

        if ($url !== null) {
            $this->dispatch('copy-to-clipboard', text: $url);
        }
    }
}
