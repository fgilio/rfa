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

        // In testing: auto-register from RFA_REPO_PATH env var (amphp server + test share env)
        if (! $project && app()->environment('testing') && isset($_ENV['RFA_REPO_PATH'])) {
            $registered = app(RegisterProjectAction::class)->handle($_ENV['RFA_REPO_PATH']);
            if ($registered->slug === $slug) {
                $project = $registered;
            }
        }

        if (! $project) {
            abort(404);
        }

        return $project->toArray();
    }
}
