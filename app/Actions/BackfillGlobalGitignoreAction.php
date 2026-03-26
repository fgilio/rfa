<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\GitMetadataService;

final readonly class BackfillGlobalGitignoreAction
{
    public function __construct(
        private GitMetadataService $git,
    ) {}

    public function handle(int $projectId, string $repoPath): ?string
    {
        $resolved = $this->git->resolveGlobalExcludesFile($repoPath);

        if ($resolved !== null) {
            Project::where('id', $projectId)->update(['global_gitignore_path' => $resolved]);
        }

        return $resolved;
    }
}
