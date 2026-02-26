<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\GitDiffService;
use Illuminate\Support\Str;

final readonly class RegisterProjectAction
{
    public function __construct(
        private GitDiffService $git,
    ) {}

    public function handle(string $directory): Project
    {
        $topLevel = $this->git->getTopLevel($directory);

        if ($topLevel === '') {
            throw new \RuntimeException("Not a git repository: {$directory}");
        }

        $path = (string) realpath($topLevel);

        // Check for existing project by canonical path
        $existing = Project::where('path', $path)->first();

        if ($existing) {
            $existing->update([
                'branch' => $this->git->getCurrentBranch($directory),
                'global_gitignore_path' => $this->git->resolveGlobalExcludesFile($path),
            ]);

            return $existing;
        }

        $gitCommonDir = $this->git->getGitCommonDir($directory);
        if ($gitCommonDir === '') {
            $gitCommonDir = $path.'/.git';
        }

        $gitDir = $this->git->getGitDir($directory);
        $isWorktree = $gitDir !== '' && $gitDir !== $gitCommonDir;

        $branch = $this->git->getCurrentBranch($directory);
        $name = basename($path);
        $slug = $this->generateUniqueSlug($name);

        return Project::create([
            'slug' => $slug,
            'name' => $name,
            'path' => $path,
            'git_common_dir' => $gitCommonDir,
            'is_worktree' => $isWorktree,
            'branch' => $branch,
            'global_gitignore_path' => $this->git->resolveGlobalExcludesFile($path),
        ]);
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Project::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
