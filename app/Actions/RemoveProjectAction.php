<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final readonly class RemoveProjectAction
{
    public function __construct(private ResolveStartupRouteAction $resolveStartupRoute) {}

    /**
     * Remove a project. When the removed project was the last-opened one,
     * returns the next route to navigate to so the caller can redirect.
     *
     * @return array{name: string, params: array<string, string>}|null
     */
    public function handle(int $projectId): ?array
    {
        $project = Project::find($projectId);

        if (! $project) {
            return null;
        }

        $slug = $project->slug;
        $wasLastOpened = Cache::get(ResolveStartupRouteAction::CACHE_KEY) === $slug;

        $project->delete();

        if ($wasLastOpened) {
            Cache::forget(ResolveStartupRouteAction::CACHE_KEY);

            return $this->resolveStartupRoute->handle();
        }

        return null;
    }
}
