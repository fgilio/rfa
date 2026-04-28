<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\RemoteTarget;
use App\Models\Project;
use App\Services\GitMetadataService;
use App\Services\GitRemoteParser;
use App\Services\RemoteUrlBuilderService;

/**
 * Resolves a project slug + target into a GitHub / GitLab URL.
 *
 * Self-heals `remote_url` on first use for repos registered before we
 * captured it. If the repo has no `origin`, we persist `''` as a sentinel
 * so subsequent loads skip the shell-out — the trade-off is that adding
 * `origin` later requires re-registering the project for the menu to
 * light up again.
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

        $remoteUrl = $project->remote_url ?? $this->resolveAndPersistRemoteUrl($project);
        if (empty($remoteUrl)) {
            return null;
        }

        $parsed = $this->parser->parse($remoteUrl);
        if ($parsed === null) {
            return null;
        }

        return $this->builder->build($parsed, RemoteTarget::fromWire($type, $params));
    }

    private function resolveAndPersistRemoteUrl(Project $project): ?string
    {
        $remoteUrl = $this->git->getRemoteUrl($project->path);
        $project->forceFill(['remote_url' => $remoteUrl ?? ''])->save();

        return $remoteUrl;
    }
}
