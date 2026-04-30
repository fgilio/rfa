<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\ExternalFilesService;

final readonly class UnlinkExternalPathAction
{
    public function __construct(
        private ExternalFilesService $externalFilesService,
    ) {}

    /**
     * Remove the external path at $index from the project, returning the new
     * list. Out-of-range indices are no-ops. Comments tied to the removed
     * mount remain in the DB but stop showing in the file list.
     *
     * @return list<array{label: string, path: string}>|null
     */
    public function handle(int $projectId, int $index): ?array
    {
        $project = Project::find($projectId);
        if ($project === null) {
            return null;
        }

        $current = $this->externalFilesService->normalizeForStorage((array) ($project->external_paths ?? []));

        if ($index < 0 || $index >= count($current)) {
            return $current;
        }

        array_splice($current, $index, 1);

        $project->forceFill(['external_paths' => $current])->save();

        return $current;
    }
}
