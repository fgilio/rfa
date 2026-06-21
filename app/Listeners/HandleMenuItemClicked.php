<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveProjectByIdAction;
use App\Actions\ScanDirectoryDialogAction;
use App\Events\ShowShortcutsRequested;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Window;

final readonly class HandleMenuItemClicked
{
    /**
     * Cross-process channel: the renderer page's mount() writes the active
     * project id here so the main-process menu listener can resolve which
     * project the user is on when "Show Context" fires.
     */
    public const string ACTIVE_PROJECT_CACHE_KEY = 'rfa.active-project-id';

    public function handle(MenuItemClicked $event): void
    {
        $id = $event->item['id'] ?? null;

        app(RecordRuntimeDiagnosticAction::class)->handle('menu.clicked', [
            'id' => $id,
        ]);

        match ($id) {
            'open-repo' => $this->handleOpenRepo(),
            'scan-directory' => app(ScanDirectoryDialogAction::class)->handle(),
            'check-updates' => $this->handleCheckUpdates(),
            'show-context' => $this->navigateToActiveProject('context-page', 'menu.show_context.completed'),
            'review-code' => $this->navigateToActiveProject('review-page', 'menu.review_code.completed'),
            'show-shortcuts' => ShowShortcutsRequested::dispatch(),
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
            app(RecordRuntimeDiagnosticAction::class)->handle('menu.open_repo.completed', [
                'project_id' => $project->id,
                'project_slug' => $project->slug,
            ]);

            Window::get('main')->url(route('review-page', ['slug' => $project->slug]));
        }
    }

    /**
     * Resolve the active project from the renderer's mount-time cache write and
     * point the main window at the given route for it. Falls back to the file
     * picker when the cache is empty or stale (project deleted, or user is on
     * select-repo-page which forgets the key).
     */
    private function navigateToActiveProject(string $routeName, string $diagnostic): void
    {
        $cachedId = Cache::get(self::ACTIVE_PROJECT_CACHE_KEY);
        $project = is_int($cachedId) ? app(ResolveProjectByIdAction::class)->handle($cachedId) : null;

        if (! $project) {
            $project = app(OpenRepositoryDialogAction::class)->handle();
        }

        if ($project) {
            app(RecordRuntimeDiagnosticAction::class)->handle($diagnostic, [
                'project_id' => $project->id,
                'project_slug' => $project->slug,
                'used_cached_project' => is_int($cachedId),
            ]);

            Window::get('main')->url(route($routeName, ['slug' => $project->slug]));
        }
    }
}
