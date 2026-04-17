<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

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

        $wasLastOpened = $this->resolveStartupRoute->forgetIfLastOpened($project->slug);

        $project->delete();

        if ($wasLastOpened) {
            return $this->resolveStartupRoute->handle();
        }

        return null;
    }
}
