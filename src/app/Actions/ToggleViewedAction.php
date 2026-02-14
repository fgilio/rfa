<?php

declare(strict_types=1);

namespace App\Actions;

final readonly class ToggleViewedAction
{
    /**
     * @param  array<int, string>  $viewedFiles
     * @param  array<int, string>  $knownPaths
     * @return array<int, string>|null
     */
    public function handle(array $viewedFiles, string $filePath, array $knownPaths): ?array
    {
        if (! in_array($filePath, $knownPaths)) {
            return null;
        }

        if (in_array($filePath, $viewedFiles)) {
            return array_values(array_diff($viewedFiles, [$filePath]));
        }

        $viewedFiles[] = $filePath;

        return $viewedFiles;
    }
}
