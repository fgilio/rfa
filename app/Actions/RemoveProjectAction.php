<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class RemoveProjectAction
{
    public function __construct(private ResolveStartupRouteAction $resolveStartupRoute) {}

    /**
     * Remove a project. When the removed project was the last-opened one,
     * returns a URL for the next route so the caller can redirect.
     */
    public function handle(int $projectId): ?string
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
