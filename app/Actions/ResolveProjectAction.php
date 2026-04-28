<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\GitMetadataService;

final readonly class ResolveProjectAction
{
    public function __construct(private GitMetadataService $git) {}

    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $slug, bool $touch = false): ?array
    {
        $project = Project::where('slug', $slug)->first();

        if ($project === null) {
            return null;
        }

        // Backfill `remote_url` for projects registered before the column existed.
        // We persist '' as a sentinel for "checked, no origin" so we don't shell
        // out to git on every page load for repos that genuinely have no remote.
        if ($project->remote_url === null) {
            $project->forceFill(['remote_url' => $this->git->getRemoteUrl($project->path) ?? ''])->save();
        }

        if ($touch) {
            $project->touch();
        }

        return $project->toArray();
    }
}
