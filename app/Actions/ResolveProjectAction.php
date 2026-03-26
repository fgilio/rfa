<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class ResolveProjectAction
{
    /**
     * @return array<string, mixed>
     */
    public function handle(string $slug): array
    {
        $project = Project::where('slug', $slug)->first();

        if (! $project) {
            abort(404);
        }

        return $project->toArray();
    }
}
