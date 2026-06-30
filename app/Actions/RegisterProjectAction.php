<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\GitCommandException;
use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use App\Services\GitMetadataService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class RegisterProjectAction
{
    public function __construct(
        private GitMetadataService $git,
    ) {}

    public function handle(string $directory): Project
    {
        // `git rev-parse --show-toplevel` fails (GitCommandException) outside a
        // repository and occasionally returns empty; both mean "not a git repo".
        // Map them to a dedicated exception so callers can distinguish this
        // expected condition from a genuine infrastructure error.
        try {
            $topLevel = $this->git->getTopLevel($directory);
        } catch (GitCommandException) {
            throw NotAGitRepositoryException::for($directory);
        }

        if ($topLevel === '') {
            throw NotAGitRepositoryException::for($directory);
        }

        $path = (string) realpath($topLevel);

        $gitCommonDir = $this->git->getGitCommonDir($directory);
        if ($gitCommonDir === '') {
            $gitCommonDir = $path.'/.git';
        }

        $gitDir = $this->git->getGitDir($directory);
        $isWorktree = $gitDir !== '' && $gitDir !== $gitCommonDir;

        // Check for existing project by canonical path
        $existing = Project::where('path', $path)->first();

        if ($existing) {
            // Branch is owned by the review page's divergence logic once a project
            // exists; refresh everything derivable from the on-disk repo so a
            // `git remote set-url`, a global-gitignore edit, or a worktree
            // conversion is picked up on re-registration.
            $existing->update([
                'git_common_dir' => $gitCommonDir,
                'is_worktree' => $isWorktree,
                'remote_url' => $this->git->getRemoteUrl($path),
                'global_gitignore_path' => $this->git->resolveGlobalExcludesFile($path),
            ]);

            return $existing;
        }

        $name = basename($path);

        $attributes = [
            'slug' => $this->generateUniqueSlug($name),
            'name' => $name,
            'path' => $path,
            'git_common_dir' => $gitCommonDir,
            'is_worktree' => $isWorktree,
            'branch' => $this->git->getCurrentBranch($directory),
            'remote_url' => $this->git->getRemoteUrl($path),
            'global_gitignore_path' => $this->git->resolveGlobalExcludesFile($path),
            // Seed a sensible base only on first registration; once the project
            // exists this is a user-editable setting we must not clobber.
            'default_base_branch' => $this->git->detectDefaultBaseBranch($path),
        ];

        try {
            return Project::create($attributes);
        } catch (UniqueConstraintViolationException $e) {
            // Lost a check-then-create race. If the same repo was registered
            // concurrently, return that row; if only the slug collided with a
            // different project, regenerate it and retry once.
            $existing = Project::where('path', $path)->first();
            if ($existing !== null) {
                return $existing;
            }

            $attributes['slug'] = $this->generateUniqueSlug($name);

            return Project::create($attributes);
        }
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
