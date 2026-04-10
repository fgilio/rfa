<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class ResolveProjectAction
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $slug, bool $touch = false): ?array
    {
        $project = Project::where('slug', $slug)->first();

        if ($project === null) {
            return null;
        }

        if ($touch) {
            $project->touch();
        }

        return $project->toArray();
    }
}
