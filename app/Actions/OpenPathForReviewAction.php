<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\ExternalFilesService;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Throwable;

final readonly class OpenPathForReviewAction
{
    public function __construct(
        private OpenProjectFromPathAction $openProject,
        private EnsureFileWorkspaceAction $ensureFileWorkspace,
        private LinkExternalPathAction $linkExternalPath,
        private ExternalFilesService $externalFilesService,
    ) {}

    /** @return array{project: Project, filePath: ?string}|null */
    public function handle(string $path): ?array
    {
        $parentPath = realpath(dirname($path));

        if ($parentPath === false) {
            Context::add('rfa.reason', 'path_not_found');

            return null;
        }

        $lexicalPath = $parentPath.DIRECTORY_SEPARATOR.basename($path);

        if (! is_link($lexicalPath) && File::isDirectory($lexicalPath)) {
            $project = $this->openProject->handle($lexicalPath);

            return $project === null ? null : $this->target($project);
        }

        $project = $this->openProject->handle($parentPath);

        if ($project !== null) {
            if (! is_link($lexicalPath) && ! File::isFile($lexicalPath)) {
                Context::add('rfa.reason', 'path_not_found');

                return null;
            }

            $relativePath = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                substr($lexicalPath, strlen(rtrim($project->path, DIRECTORY_SEPARATOR)) + 1),
            );

            return $this->target($project, $relativePath);
        }

        if (Context::get('rfa.reason') !== 'not_a_git_repository') {
            return null;
        }

        Context::forget(['rfa.reason', 'rfa.error_class']);

        $externalPath = $this->externalFilesService->canonicalFilePath($lexicalPath);
        if ($externalPath === null) {
            Context::add('rfa.reason', 'path_not_found');

            return null;
        }

        try {
            $workspace = $this->ensureFileWorkspace->handle();
            $externalPaths = $this->linkExternalPath->handle($workspace->id, $externalPath);
        } catch (Throwable $exception) {
            Context::add('rfa.reason', 'file_workspace_failed');
            Context::add('rfa.error_class', $exception::class);

            return null;
        }

        $linkedFile = collect($externalPaths)
            ->first(fn (array $row): bool => realpath($row['path']) === $externalPath);

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
