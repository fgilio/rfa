<?php

namespace Tests\Helpers;

use App\Models\Project;
use Illuminate\Support\Facades\File;

trait InteractsWithTestRepositories
{
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

        return $this->execOrThrow(
            'cd '.escapeshellarg($directory)." && {$command}",
            "Test repository command failed in [{$directory}]: {$command}",
        );
    }

    private function execOrThrow(string $command, string $errorPrefix): string
    {
        $output = [];
        $exitCode = 0;
        exec($command.' 2>&1', $output, $exitCode);

        if ($exitCode === 0) {
            return implode("\n", $output);
        }

        throw new \RuntimeException(
            "{$errorPrefix} (exit code {$exitCode}):\n".implode("\n", $output)
        );
    }
}
