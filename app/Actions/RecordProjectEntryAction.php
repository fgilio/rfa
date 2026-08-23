<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Cache;

/**
 * Records "the user is now in project X" on every page that enters a project.
 *
 * Two separate cached values, deliberately not merged into one record because
 * they answer different questions with different lifetimes:
 *
 * - The **active project id** (one day) is what the native menu reads when the
 *   user picks "Review code" / "Show context" without a window in focus. It
 *   expires so a stale menu never reopens a project the user abandoned days ago.
 * - The **last-opened slug** (forever, owned by {@see ResolveStartupRouteAction})
 *   is what startup restores. It has to outlive any session, so it never expires.
 *
 * Every page that enters a project writes both, so Review and Context record
 * the same identity and startup restores whichever the user was in last.
 * Deleted projects and expired values fall back safely: the startup resolver
 * drops an unknown slug, and the menu opens the file picker when the id
 * resolves to nothing.
 *
 * Deferred view persistence stays separate. {@see PersistProjectViewAction}
 * records which page of a project, not which project.
 */
final readonly class RecordProjectEntryAction
{
    private const string ACTIVE_PROJECT_CACHE_KEY = 'rfa.active-project-id';

    public function __construct(
        private ResolveStartupRouteAction $resolveStartupRoute,
    ) {}

    public function handle(int $projectId, string $slug): void
    {
        $this->resolveStartupRoute->rememberLastOpened($slug);

        Cache::put(self::ACTIVE_PROJECT_CACHE_KEY, $projectId, now()->addDay());
    }

    public function activeProjectId(): ?int
    {
        $projectId = Cache::get(self::ACTIVE_PROJECT_CACHE_KEY);

        return is_int($projectId) ? $projectId : null;
    }

    /**
     * Forget the active project without touching the last-opened slug. The
     * repo picker shows that slug as the current selection while the native
     * menu falls back to the file picker.
     */
    public function forgetActiveProject(): void
    {
        Cache::forget(self::ACTIVE_PROJECT_CACHE_KEY);
    }
}
