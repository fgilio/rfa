<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Actions\BuildRemoteUrlAction;
use Native\Desktop\Facades\Shell;

/**
 * The URL is always rebuilt server-side from the project's stored `remote_url`
 * so the trait never trusts a URL passed from the DOM.
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
