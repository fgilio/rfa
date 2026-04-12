<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final readonly class RemoveProjectAction
{
    public function handle(int $projectId): void
    {
        $project = Project::find($projectId);

        if (! $project) {
            return;
        }

        $slug = $project->slug;
        $project->delete();

        if (Cache::get(ResolveStartupRouteAction::CACHE_KEY) === $slug) {
            Cache::forget(ResolveStartupRouteAction::CACHE_KEY);
        }
    }
}
