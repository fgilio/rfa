<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class UpdateProjectSettingAction
{
    private const ALLOWED_ATTRIBUTES = ['respect_global_gitignore', 'branch', 'default_base_branch'];

    /** @param array<string, mixed> $attributes */
    public function handle(int $projectId, array $attributes): void
    {
        $safe = array_intersect_key($attributes, array_flip(self::ALLOWED_ATTRIBUTES));

        if ($safe !== []) {
            Project::where('id', $projectId)->update($safe);
        }
    }
}
