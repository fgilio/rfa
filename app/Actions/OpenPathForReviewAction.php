<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Throwable;

final readonly class OpenPathForReviewAction
{
    public function __construct(
        private OpenProjectFromPathAction $openProject,
        private EnsureFileWorkspaceAction $ensureFileWorkspace,
        private LinkExternalPathAction $linkExternalPath,
    ) {}

    /** @return array{project: Project, filePath: ?string}|null */
    public function handle(string $path): ?array
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            Context::add('rfa.reason', 'path_not_found');

            return null;
        }

        if (File::isDirectory($realPath)) {
            $project = $this->openProject->handle($realPath);

            return $project === null ? null : $this->target($project);
        }

        if (! File::isFile($realPath)) {
            Context::add('rfa.reason', 'unsupported_path_type');

            return null;
        }

        $project = $this->openProject->handle(dirname($realPath));

        if ($project !== null) {
            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($realPath, strlen(rtrim($project->path, DIRECTORY_SEPARATOR)) + 1),
            );

            return $this->target($project, $relativePath);
        }

        if (Context::get('rfa.reason') !== 'not_a_git_repository') {
            return null;
        }

        Context::forget(['rfa.reason', 'rfa.error_class']);

        try {
            $workspace = $this->ensureFileWorkspace->handle();
            $externalPaths = $this->linkExternalPath->handle($workspace->id, $realPath);
        } catch (Throwable $exception) {
            Context::add('rfa.reason', 'file_workspace_failed');
            Context::add('rfa.error_class', $exception::class);

            return null;
        }

        $linkedFile = collect($externalPaths)
            ->first(fn (array $row): bool => realpath($row['path']) === $realPath);

        if ($linkedFile === null) {
            Context::add('rfa.reason', 'external_file_link_failed');

            return null;
        }

        return $this->target($workspace, 'external/'.$linkedFile['label']);
    }

    /** @return array{project: Project, filePath: ?string} */
    private function target(Project $project, ?string $filePath = null): array
    {
        return compact('project', 'filePath');
    }
}
