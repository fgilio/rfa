<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class LinkExternalPathAction
{
    /**
     * Append an external directory link to a project. Idempotent on `path`:
     * the same absolute path is never added twice.
     *
     * Returns the project's updated `external_paths` value (a list of
     * `{label: string, path: string}` rows), or null if the project does
     * not exist or the path is invalid.
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

        /** @var list<array{label: string, path: string}> $current */
        $current = collect($project->external_paths ?? [])
            ->filter(fn ($row): bool => is_array($row) && isset($row['path']) && is_string($row['path']))
            ->map(fn (array $row): array => [
                'label' => isset($row['label']) && is_string($row['label']) && trim($row['label']) !== ''
                    ? $row['label']
                    : basename((string) $row['path']),
                'path' => (string) $row['path'],
            ])
            ->values()
            ->all();

        // Idempotent: skip if already linked at the same canonical path.
        foreach ($current as $row) {
            if (realpath($row['path']) === $real) {
                return $current;
            }
        }

        $finalLabel = $label !== null && trim($label) !== '' ? trim($label) : basename($real);

        $current[] = ['label' => $finalLabel, 'path' => $real];

        $project->forceFill(['external_paths' => $current])->save();

        return $current;
    }
}
