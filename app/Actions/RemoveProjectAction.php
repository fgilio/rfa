<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class RemoveProjectAction
{
    public function __construct(private ResolveStartupRouteAction $resolveStartupRoute) {}

    /**
     * Remove a project. When the removed project was the last-opened one,
     * returns the select-repo URL so the caller can redirect the user to
     * explicitly pick a new repo. Returns null otherwise.
     */
    public function handle(int $projectId): ?string
    {
        $project = Project::find($projectId);

        if (! $project) {
            return null;
        }

        $wasLastOpened = $this->resolveStartupRoute->lastOpenedSlug() === $project->slug;

        $project->delete();

        return $wasLastOpened ? $this->resolveStartupRoute->handle() : null;
    }
}
