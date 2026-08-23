<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\OpenRepositoryDialogAction;
use App\Actions\RecordRuntimeDiagnosticAction;
use App\Actions\ResolveProjectByIdAction;
use App\Actions\ScanDirectoryDialogAction;
use App\Actions\UpdaterStateAction;
use App\Events\ShowShortcutsRequested;
use App\Events\ToggleSidebarRequested;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Events\Menu\MenuItemClicked;
use Native\Desktop\Facades\AutoUpdater;
use Native\Desktop\Facades\Window;
use Throwable;

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
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'skipped';

        try {
            $id = $event->item['id'] ?? null;

            Context::add('rfa.menu_id', $id);

            app(RecordRuntimeDiagnosticAction::class)->handle('menu.clicked', [
                'id' => $id,
            ]);

            $outcome = match ($id) {
                'open-repo' => $this->handleOpenRepo(),
                'scan-directory' => $this->handleScanDirectory(),
                'check-updates' => $this->handleCheckUpdates(),
                'show-context' => $this->navigateToActiveProject('context-page', 'menu.show_context.completed'),
                'review-code' => $this->navigateToActiveProject('review-page', 'menu.review_code.completed'),
                'show-shortcuts' => $this->handleShowShortcuts(),
                'toggle-sidebar' => $this->handleToggleSidebar(),
                default => 'skipped',
            };
        } catch (Throwable $e) {
            $outcome = 'error';
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'menu_click_failed');

            throw $e;
        } finally {
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('menu.item.clicked');
        }
    }

    private function handleCheckUpdates(): string
    {
        // Show the spinner before asking Electron to check: the updater can
        // take a moment to emit CheckingForUpdate, and on a dev build it
        // never reports back at all.
        app(UpdaterStateAction::class)->beginCheck();

        AutoUpdater::checkForUpdates();

        return 'completed';
    }

    private function handleScanDirectory(): string
    {
        $result = app(ScanDirectoryDialogAction::class)->handle();

        if (! $result) {
            return 'cancelled';
        }

        Context::add('rfa.repos_found', $result->found);
        Context::add('rfa.repos_registered', $result->registered);
        Context::add('rfa.repos_already_tracked', $result->alreadyTracked);
        Context::add('rfa.repos_failed', $result->failed);

        return $result->failed > 0 ? 'partial' : 'completed';
    }

    private function handleShowShortcuts(): string
    {
        ShowShortcutsRequested::dispatch();

        return 'completed';
    }

    private function handleToggleSidebar(): string
    {
        ToggleSidebarRequested::dispatch();

        return 'completed';
    }

    private function handleOpenRepo(): string
    {
        $project = app(OpenRepositoryDialogAction::class)->handle();

        if (! $project) {
            return $this->outcomeForNullProject();
        }

        Context::add('rfa.project_id', $project->id);
        Context::add('rfa.project_slug', $project->slug);

        app(RecordRuntimeDiagnosticAction::class)->handle('menu.open_repo.completed', [
            'project_id' => $project->id,
            'project_slug' => $project->slug,
        ]);

        Window::get('main')->url(route('review-page', ['slug' => $project->slug]));

        return 'completed';
    }

    /**
     * Resolve the active project from the renderer's mount-time cache write and
     * point the main window at the given route for it. Falls back to the file
     * picker when the cache is empty or stale (project deleted, or user is on
     * select-repo-page which forgets the key).
     */
    private function navigateToActiveProject(string $routeName, string $diagnostic): string
    {
        $cachedId = Cache::get(self::ACTIVE_PROJECT_CACHE_KEY);
        $project = is_int($cachedId) ? app(ResolveProjectByIdAction::class)->handle($cachedId) : null;
        $usedCachedProject = $project !== null;

        if (! $project) {
            $project = app(OpenRepositoryDialogAction::class)->handle();
        }

        if (! $project) {
            return $this->outcomeForNullProject();
        }

        Context::add('rfa.project_id', $project->id);
        Context::add('rfa.project_slug', $project->slug);
        Context::add('rfa.used_cached_project', $usedCachedProject);

        app(RecordRuntimeDiagnosticAction::class)->handle($diagnostic, [
            'project_id' => $project->id,
            'project_slug' => $project->slug,
            'used_cached_project' => $usedCachedProject,
        ]);

        Window::get('main')->url(route($routeName, ['slug' => $project->slug]));

        return 'completed';
    }

    /**
     * Map a null project from OpenRepositoryDialogAction to its outcome.
     *
     * The action marks non-dismissal causes via Context (rfa.reason), so
     * a plain dismissal is the only null left unmarked.
     */
    private function outcomeForNullProject(): string
    {
        return match (Context::get('rfa.reason')) {
            'project_registration_failed' => 'error',
            'not_a_git_repository' => 'rejected',
            default => 'cancelled',
        };
    }
}
