<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class ResolveProjectAction
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $slug): ?array
    {
        return Project::where('slug', $slug)->first()?->toArray();
    }
}
