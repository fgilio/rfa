<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class UpdateProjectSettingAction
{
    /** @param array<string, mixed> $attributes */
    public function handle(int $projectId, array $attributes): void
    {
        Project::where('id', $projectId)->update($attributes);
    }
}
