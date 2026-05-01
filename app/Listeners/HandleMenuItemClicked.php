<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\ResolveProjectByIdAction;
use App\Actions\ScanDirectoryDialogAction;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Window;

final readonly class HandleMenuItemClicked
{
    public function handle(MenuItemClicked $event): void
    {
        $id = $event->item['id'] ?? null;

        match ($id) {
            'open-repo' => $this->handleOpenRepo(),
            'scan-directory' => app(ScanDirectoryDialogAction::class)->handle(),
            'check-updates' => $this->handleCheckUpdates(),
            'show-context' => $this->handleShowContext(),
            default => null,
        };
    }

    private function handleCheckUpdates(): void
    {
        Cache::put('native-update-state', [
            'status' => 'checking',
            'startedAt' => now()->timestamp,
            'simulateTerminalState' => config('app.debug'),
        ], now()->addMinutes(2));

        AutoUpdater::checkForUpdates();
    }

    private function handleOpenRepo(): void
    {
        $project = app(OpenRepositoryDialogAction::class)->handle();

        if ($project) {
            Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
        }
    }

    /**
     * Resolve the active project from the renderer's mount-time cache write.
     * Falls back to the file picker when the cache is empty or stale (project
     * deleted, or user is on select-repo-page which forgets the key).
     */
    private function handleShowContext(): void
    {
        $cachedId = Cache::get('rfa.active-project-id');
        $project = is_int($cachedId) ? app(ResolveProjectByIdAction::class)->handle($cachedId) : null;

        if (! $project) {
            $project = app(OpenRepositoryDialogAction::class)->handle();
        }

        if ($project) {
            Window::get('main')->url(route('context-page', ['slug' => $project->slug]));
        }
    }
}
