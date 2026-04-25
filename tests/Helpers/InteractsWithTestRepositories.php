<?php

namespace Tests\Helpers;

use App\Models\Project;
use Illuminate\Support\Facades\File;

trait InteractsWithTestRepositories
{
    /**
     * Per-process cached path to a pre-initialized `.git` directory used as a
     * template. New test repos are created by copying this directory instead
     * of running `git init` + `git config` four times each.
     */
    private static ?string $cachedRepoTemplate = null;

    /** Set git env vars once per PHP process so we don't need `git config` calls. */
    private static bool $gitEnvironmentInitialized = false;

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createTestProject(array $overrides = []): Project
    {
        $slug = $overrides['slug'] ?? 'test-proj-'.uniqid();
        $path = $overrides['path'] ?? '/tmp/'.$slug;

        return Project::create(array_merge([
            'slug' => $slug,
            'name' => $slug,
            'path' => $path,
            'git_common_dir' => $path.'/.git',
            'is_worktree' => false,
        ], $overrides));
    }

    /** @var list<string> */
    protected array $trackedTempDirectories = [];

    protected function createTempDirectory(string $prefix = 'rfa_test_'): string
    {
        $path = sys_get_temp_dir().'/'.$prefix.getmypid().'_'.uniqid('', true);

        File::makeDirectory($path, 0755, true);

        return $this->trackTempDirectory($path);
    }

    protected function trackTempDirectory(string $path): string
    {
        if (! in_array($path, $this->trackedTempDirectories, true)) {
            $this->trackedTempDirectories[] = $path;
        }

        return $path;
    }

    protected function cleanupTrackedTempDirectories(): void
    {
        $paths = array_values(array_unique($this->trackedTempDirectories));

        usort($paths, fn (string $left, string $right) => strlen($right) <=> strlen($left));

        foreach ($paths as $path) {
            if (is_file($path)) {
                File::delete($path);

                continue;
            }

            if (File::isDirectory($path)) {
                File::deleteDirectory($path);
            }
        }

        $this->trackedTempDirectories = [];
    }

    protected function initTestRepo(string $directory): void
    {
        $this->ensureGitTestEnvironment();

        $template = $this->ensureRepoTemplate();

        // Copy the pre-initialized .git directory into the test directory.
        // Single fork — replaces 4 git invocations (init + 3 config).
        $output = [];
        $exitCode = 0;
        exec(
            'cp -R '.escapeshellarg($template).' '.escapeshellarg(rtrim($directory, '/').'/.git').' 2>&1',
            $output,
            $exitCode,
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                "Failed to copy git template into [{$directory}]: ".implode("\n", $output)
            );
        }
    }

    protected function commitTestRepo(string $directory, string $message = 'commit'): void
    {
        $this->ensureGitTestEnvironment();

        $this->runTestRepoCommand($directory, [
            'git add -A',
            'git commit -q -m '.escapeshellarg($message),
        ]);
    }

    protected function runTestRepoCommand(string $directory, array|string $commands): string
    {
        $command = is_array($commands)
            ? implode(' && ', $commands)
            : $commands;

        $output = [];
        $exitCode = 0;

        exec('cd '.escapeshellarg($directory)." && {$command} 2>&1", $output, $exitCode);

        if ($exitCode === 0) {
            return implode("\n", $output);
        }

        $details = implode("\n", $output);

        throw new \RuntimeException(
            "Test repository command failed in [{$directory}] with exit code {$exitCode}: {$command}\n{$details}"
        );
    }

    /**
     * Set git author/committer + commit.gpgsign env vars so we don't need
     * `git config` calls per test. These propagate to all child git processes
     * (including the production `GitProcessService`).
     */
    private function ensureGitTestEnvironment(): void
    {
        if (self::$gitEnvironmentInitialized) {
            return;
        }

        putenv('GIT_AUTHOR_NAME=RFA Test');
        putenv('GIT_AUTHOR_EMAIL=test@rfa.test');
        putenv('GIT_COMMITTER_NAME=RFA Test');
        putenv('GIT_COMMITTER_EMAIL=test@rfa.test');
        putenv('GIT_CONFIG_COUNT=1');
        putenv('GIT_CONFIG_KEY_0=commit.gpgsign');
        putenv('GIT_CONFIG_VALUE_0=false');

        self::$gitEnvironmentInitialized = true;
    }

    /**
     * Lazily create a per-process `.git` template directory by running
     * `git init -b main` exactly once. Subsequent `initTestRepo()` calls
     * within the same process copy from this template.
     */
    private function ensureRepoTemplate(): string
    {
        if (self::$cachedRepoTemplate !== null && is_dir(self::$cachedRepoTemplate)) {
            return self::$cachedRepoTemplate;
        }

        $base = sys_get_temp_dir().'/rfa_repo_tpl_'.getmypid().'_'.bin2hex(random_bytes(4));

        File::makeDirectory($base, 0755, true);

        $output = [];
        $exitCode = 0;
        exec('git init -b main -q '.escapeshellarg($base).' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            File::deleteDirectory($base);

            throw new \RuntimeException(
                'Failed to initialize git repo template: '.implode("\n", $output)
            );
        }

        register_shutdown_function(static function () use ($base): void {
            // Use raw PHP recursion — facades aren't available at shutdown.
            self::removeDirectoryRecursive($base);
        });

        return self::$cachedRepoTemplate = $base.'/.git';
    }

    private static function removeDirectoryRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }

        @rmdir($path);
    }
}
