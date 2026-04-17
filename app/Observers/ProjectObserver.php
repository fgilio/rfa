<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\ResolveStartupRouteAction;
use App\Models\Project;

final readonly class ProjectObserver
{
    public function __construct(private ResolveStartupRouteAction $resolver) {}

    public function deleted(Project $project): void
    {
        if ($this->resolver->lastOpenedSlug() === $project->slug) {
            $this->resolver->forgetLastOpened();
        }
    }
}
