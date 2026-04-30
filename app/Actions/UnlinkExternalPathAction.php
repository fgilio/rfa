<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class UnlinkExternalPathAction
{
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

        $current = array_values((array) ($project->external_paths ?? []));

        if ($index < 0 || $index >= count($current)) {
            return $this->normalize($current);
        }

        array_splice($current, $index, 1);

        $project->forceFill(['external_paths' => $current])->save();

        return $this->normalize($current);
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{label: string, path: string}>
     */
    private function normalize(array $rows): array
    {
        return collect($rows)
            ->filter(fn ($row): bool => is_array($row) && isset($row['path']) && is_string($row['path']))
            ->map(fn (array $row): array => [
                'label' => isset($row['label']) && is_string($row['label']) && trim($row['label']) !== ''
                    ? $row['label']
                    : basename((string) $row['path']),
                'path' => (string) $row['path'],
            ])
            ->values()
            ->all();
    }
}
