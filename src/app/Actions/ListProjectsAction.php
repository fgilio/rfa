<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class ListProjectsAction
{
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function handle(): array
    {
        return Project::orderBy('name')
            ->get()
            ->groupBy('git_common_dir')
            ->map(fn ($group) => $group->map->toArray()->values()->all())
            ->all();
    }
}
