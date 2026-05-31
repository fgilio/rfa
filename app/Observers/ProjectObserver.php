<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;
use App\Models\TrashedFile;

final readonly class ProjectObserver
{
    public function __construct(private ResolveStartupRouteAction $resolver) {}

    /**
     * Purge the project's trashed-file content blobs before it is deleted.
     *
     * The `trashed_files.project_id` FK is a DB-level cascade, which removes the
     * rows WITHOUT firing TrashedFile::deleting — so the blobs would otherwise be
     * orphaned on disk forever. Deleting each model here fires that event (which
     * purges the blob) and clears the rows, leaving the cascade nothing to do.
     */
    public function deleting(Project $project): void
    {
        $project->trashedFiles()
            ->get()
            ->each(fn (TrashedFile $trashedFile) => $trashedFile->delete());
    }

    public function deleted(Project $project): void
    {
        if ($this->resolver->lastOpenedSlug() === $project->slug) {
            $this->resolver->forgetLastOpened();
        }
    }
}
