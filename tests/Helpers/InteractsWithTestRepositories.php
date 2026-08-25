<?php

namespace Tests\Helpers;

use App\Models\Project;
use Illuminate\Support\Facades\File;

trait InteractsWithTestRepositories
{
    /**
     * Neutralize the developer's global and system git config for every command
     * the harness runs. Without it a fixture repo inherits whatever the machine
     * configures — a global `core.excludesFile` containing `*.log` makes
     * `git add -A` stage nothing and the initial commit fails outright. CI has
     * no global config, so such a test passes there and fails only locally.
     *
     * `phpunit.xml` sets the same two variables process-wide, which also covers
     * the git calls application code makes. This prefix keeps fixture setup
     * deterministic even when a test changes the ambient environment.
     */
    private const GIT_CONFIG_ISOLATION = 'export GIT_CONFIG_GLOBAL=/dev/null GIT_CONFIG_SYSTEM=/dev/null;';

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
        $template = RepoTemplate::path(fn (string $cmd, string $err) => $this->execOrThrow($cmd, $err));
        $target = rtrim($directory, '/').'/.git';

        $this->execOrThrow(
            'cp -R '.escapeshellarg($template).' '.escapeshellarg($target),
            "Failed to copy git template into [{$directory}]",
        );
    }

    protected function commitTestRepo(string $directory, string $message = 'commit'): void
    {
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

        try {
            return $this->execOrThrow(
                'cd '.escapeshellarg($directory)." && {$command}",
                "Test repository command failed in [{$directory}]: {$command}",
            );
        } catch (\RuntimeException $exception) {
            throw new \RuntimeException(
                $exception->getMessage()."\n".$this->describeRepositoryObjectStore($directory),
                previous: $exception,
            );
        }
    }

    /**
     * Snapshot the fixture repo's object store for flake forensics. A
     * "bad tree object" failure means loose objects vanished mid-test
     * (issue #133), and this records what survived at failure time.
     */
    private function describeRepositoryObjectStore(string $directory): string
    {
        $output = [];
        exec(
            self::GIT_CONFIG_ISOLATION.' (cd '.escapeshellarg($directory).' && git count-objects -v && git fsck --no-progress) 2>&1',
            $output,
        );

        return "Object store state:\n".implode("\n", $output);
    }

    private function execOrThrow(string $command, string $errorPrefix): string
    {
        $output = [];
        $exitCode = 0;
        exec(self::GIT_CONFIG_ISOLATION.' '.$command.' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            return implode("\n", $output);
        }

        throw new \RuntimeException(
            "{$errorPrefix} (exit code {$exitCode}):\n".implode("\n", $output)
        );
    }
}
