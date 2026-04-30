<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\ExternalFilesService;

final readonly class LinkExternalPathAction
{
    public function __construct(
        private ExternalFilesService $externalFilesService,
    ) {}

    /**
     * Append an external directory link to a project. Idempotent on the
     * canonical path: the same directory is never linked twice. Returns the
     * project's updated `external_paths` value, or null if the project does
     * not exist or the path is not a directory.
     *
     * @return list<array{label: string, path: string}>|null
     */
    public function handle(int $projectId, string $path, ?string $label = null): ?array
    {
        return $this->handleForProject(Project::find($projectId), $path, $label);
    }

    /**
     * Same as `handle()` but accepts a slug/name/numeric id as a single string.
     * Used by the CLI so console commands don't need to import the Project model.
     *
     * @return list<array{label: string, path: string}>|null
     */
    public function handleByReference(string $reference, string $path, ?string $label = null): ?array
    {
        $project = ctype_digit($reference)
            ? Project::find((int) $reference)
            : Project::where('slug', $reference)->orWhere('name', $reference)->first();

        return $this->handleForProject($project, $path, $label);
    }

    /**
     * @return list<array{label: string, path: string}>|null
     */
    private function handleForProject(?Project $project, string $path, ?string $label): ?array
    {
        if ($project === null) {
            return null;
        }

        $real = realpath($path);
        if ($real === false || ! is_dir($real)) {
            return null;
        }

        $current = $this->externalFilesService->normalizeForStorage((array) ($project->external_paths ?? []));

        foreach ($current as $row) {
            if (realpath($row['path']) === $real) {
                return $current;
            }
        }

        $current[] = [
            'label' => $label !== null && trim($label) !== '' ? trim($label) : basename($real),
            'path' => $real,
        ];

        $project->forceFill(['external_paths' => $current])->save();

        return $current;
    }
}
