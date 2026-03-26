<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class RemoveProjectAction
{
    public function handle(int $projectId): void
    {
        Project::find($projectId)?->delete();
    }
}
