<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\RemoteTarget;
use App\Models\Project;
use App\Services\GitMetadataService;
use App\Services\GitRemoteParser;
use App\Services\RemoteUrlBuilderService;

/**
 * Resolves a project slug + target description into a GitHub / GitLab URL.
 *
 * Self-heals: if the project was registered before we captured `remote_url`,
 * or if the user added an `origin` after registration, the first call fills
 * it in from `git config` and persists it.
 */
final readonly class BuildRemoteUrlAction
{
    public function __construct(
        private GitMetadataService $git,
        private GitRemoteParser $parser,
        private RemoteUrlBuilderService $builder,
    ) {}

    /** @param array<string, mixed> $params */
    public function handle(string $projectSlug, string $type, array $params = []): ?string
    {
        $project = Project::where('slug', $projectSlug)->first();
        if ($project === null) {
            return null;
        }

        $remoteUrl = $project->remote_url;
        if ($remoteUrl === null || $remoteUrl === '') {
            $remoteUrl = $this->git->getRemoteUrl($project->path);
            if ($remoteUrl === null) {
                return null;
            }
            $project->forceFill(['remote_url' => $remoteUrl])->save();
        }

        $parsed = $this->parser->parse($remoteUrl);
        if ($parsed === null) {
            return null;
        }

        $target = RemoteTarget::fromWire($type, $params);

        return $this->builder->build($parsed, $target);
    }
}
