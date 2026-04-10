<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ScanDirectoryResult;
use App\Exceptions\GitCommandException;
use App\Models\Project;
use App\Services\GitMetadataService;
use Illuminate\Support\Facades\File;

final readonly class ScanDirectoryAction
{
    public function __construct(
        private GitMetadataService $git,
        private RegisterProjectAction $register,
    ) {}

    public function handle(string $directory): ScanDirectoryResult
    {
        $realDirectory = realpath($directory);

        if ($realDirectory === false || ! File::isDirectory($realDirectory)) {
            throw new \InvalidArgumentException("Directory does not exist: {$directory}");
        }

        $children = File::directories($realDirectory);

        // Detect which children are git repo roots
        $gitRepoPaths = [];
        foreach ($children as $child) {
            $childReal = (string) realpath($child);

            try {
                $topLevel = $this->git->getTopLevel($childReal);
            } catch (GitCommandException) {
                continue;
            }

            if ($topLevel === '') {
                continue;
            }

            $topLevelReal = (string) realpath($topLevel);

            // Only register if this child IS the repo root (not a subfolder of another repo)
            if ($topLevelReal === $childReal) {
                $gitRepoPaths[] = $childReal;
            }
        }

        $found = count($gitRepoPaths);

        if ($found === 0) {
            return new ScanDirectoryResult(found: 0, registered: 0, alreadyTracked: 0, failed: 0);
        }

        // Single query to find already-tracked projects
        $existingProjects = Project::whereIn('path', $gitRepoPaths)->get();
        $trackedSet = array_flip($existingProjects->pluck('path')->all());

        $newProjects = [];
        $errors = [];

        foreach ($gitRepoPaths as $path) {
            if (isset($trackedSet[$path])) {
                continue;
            }

            try {
                $project = $this->register->handle($path);
                $newProjects[] = [
                    'name' => $project->name,
                    'path' => $project->path,
                    'slug' => $project->slug,
                    'branch' => $project->branch,
                ];
            } catch (\RuntimeException $e) {
                $errors[$path] = $e->getMessage();
            }
        }

        return new ScanDirectoryResult(
            found: $found,
            registered: count($newProjects),
            alreadyTracked: $existingProjects->count(),
            failed: count($errors),
            newProjects: $newProjects,
            errors: $errors,
        );
    }
}
