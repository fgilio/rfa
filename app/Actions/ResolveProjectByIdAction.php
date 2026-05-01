<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

/**
 * Look up a project by id. Used when a stable id has been parked somewhere
 * outside the renderer (cache, deep-link, etc.) and the caller needs to
 * resolve it back to the model without taking a Models dependency.
 */
final readonly class ResolveProjectByIdAction
{
    public function handle(int $id): ?Project
    {
        return Project::find($id);
    }
}
