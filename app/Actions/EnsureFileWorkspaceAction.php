<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\GitProcessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

final readonly class EnsureFileWorkspaceAction
{
    private const string LOCK_KEY = 'rfa.file-workspace.initialize';

    public function __construct(
        private GitProcessService $git,
        private RegisterProjectAction $registerProject,
    ) {}

    public function handle(): Project
    {
        return Cache::lock(self::LOCK_KEY, 10)->block(5, function (): Project {
            $workspacePath = (string) config('rfa.file_workspace_path');

            File::ensureDirectoryExists($workspacePath);

            if (! File::isDirectory($workspacePath.'/.git')) {
                $this->git->run($workspacePath, ['init', '--quiet', '--initial-branch=main']);
            }

            $hasHead = rescue(
                fn (): bool => trim($this->git->run($workspacePath, ['rev-parse', '--verify', 'HEAD'])) !== '',
                rescue: false,
                report: false,
            );

            if (! $hasHead) {
                $this->git->run($workspacePath, [
                    '-c', 'user.name=RFA',
                    '-c', 'user.email=rfa@localhost',
                    'commit', '--quiet', '--allow-empty', '-m', 'Initialize RFA file workspace',
                ]);
            }

            $project = $this->registerProject->handle($workspacePath);

            if ($project->name !== 'Files') {
                $project->forceFill(['name' => 'Files'])->save();
            }

            return $project;
        });
    }
}
