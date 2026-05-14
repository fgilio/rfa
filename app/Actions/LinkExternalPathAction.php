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
     * Append an external path link to a project. The path may be a directory
     * (whose contents are walked) or a single file (mounted as one entry).
     * Idempotent on the canonical path: the same path is never linked twice.
     * Returns the project's updated `external_paths` value, or null if the
     * project does not exist or the path is neither a file nor a directory.
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
        // Prefer slug/name so a project with a numeric slug or name (e.g.
        // `"123"`) still resolves correctly. Only fall back to numeric ID
        // when no slug/name match exists.
        $project = Project::where('slug', $reference)->orWhere('name', $reference)->first()
            ?? (ctype_digit($reference) ? Project::find((int) $reference) : null);

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
        if ($real === false || (! is_dir($real) && ! is_file($real))) {
            return null;
        }

        $current = $this->externalFilesService->normalizeForStorage((array) ($project->external_paths ?? []));

        foreach ($current as $row) {
            if (realpath($row['path']) === $real) {
                return $current;
            }
        }

        $candidate = $label !== null && trim($label) !== '' ? trim($label) : basename($real);

        $current[] = [
            'label' => $this->externalFilesService->uniqueLabelFor($current, $candidate),
            'path' => $real,
        ];

        $project->forceFill(['external_paths' => $current])->save();

        return $current;
    }
}
