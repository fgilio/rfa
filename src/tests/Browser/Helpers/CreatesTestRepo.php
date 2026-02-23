<?php

namespace Tests\Browser\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

trait CreatesTestRepo
{
    protected string $testRepoPath = '';

    protected string $testProjectSlug = '';

    protected function setUpTestRepo(): void
    {
        $this->testRepoPath = sys_get_temp_dir().'/rfa_browser_'.uniqid();
        File::makeDirectory($this->testRepoPath, 0755, true);

        // Initial tracked files
        File::put($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet($name) {',
            '    return "Hello, " . $name;',
            '}',
            '',
        ]));

        File::put($this->testRepoPath.'/config.php', implode("\n", [
            '<?php',
            'return [',
            "    'debug' => false,",
            "    'version' => '1.0',",
            '];',
            '',
        ]));

        // Init + config + commit in a single shell to avoid any exec isolation issues
        $this->runShell(implode(' && ', [
            'git init',
            "git config user.email 'test@rfa.test'",
            "git config user.name 'RFA Test'",
            'git add -A',
            "git commit -m 'Initial commit'",
        ]));

        // Verify HEAD exists
        $head = trim($this->runShell('git rev-parse HEAD'));
        if ($head === '' || str_contains($head, 'fatal')) {
            throw new \RuntimeException("Git setup failed: HEAD not established. Output: {$head}");
        }

        // Modify hello.php
        File::put($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet(string $name): string {',
            '    return "Hello, {$name}!";',
            '}',
            '',
        ]));

        // Add new untracked file
        File::put($this->testRepoPath.'/utils.php', implode("\n", [
            '<?php',
            'function formatDate($date) {',
            "    return date('Y-m-d', strtotime(\$date));",
            '}',
            '',
        ]));

        // Delete config.php
        File::delete($this->testRepoPath.'/config.php');

        // Set env var (shared with amphp server process - auto-registers via AppServiceProvider)
        $_ENV['RFA_REPO_PATH'] = $this->testRepoPath;

        // Predict slug (matches RegisterProjectAction's slug generation)
        $this->testProjectSlug = Str::slug(basename($this->testRepoPath));
    }

    protected function setUpEmptyTestRepo(): void
    {
        $this->testRepoPath = sys_get_temp_dir().'/rfa_browser_'.uniqid();
        File::makeDirectory($this->testRepoPath, 0755, true);

        File::put($this->testRepoPath.'/README.md', "# Test\n");

        $this->runShell(implode(' && ', [
            'git init',
            "git config user.email 'test@rfa.test'",
            "git config user.name 'RFA Test'",
            'git add -A',
            "git commit -m 'Initial commit'",
        ]));

        // Verify HEAD exists
        $head = trim($this->runShell('git rev-parse HEAD'));
        if ($head === '' || str_contains($head, 'fatal')) {
            throw new \RuntimeException("Git setup failed: HEAD not established. Output: {$head}");
        }

        // Set env var (shared with amphp server process - auto-registers via AppServiceProvider)
        $_ENV['RFA_REPO_PATH'] = $this->testRepoPath;

        // Predict slug (matches RegisterProjectAction's slug generation)
        $this->testProjectSlug = Str::slug(basename($this->testRepoPath));
    }

    protected function addLargeFile(string $name = 'large.txt', int $bytes = 600_000): void
    {
        File::put($this->testRepoPath.'/'.$name, str_repeat("line of content for large file\n", (int) ceil($bytes / 30)));
    }

    protected function tearDownTestRepo(): void
    {
        unset($_ENV['RFA_REPO_PATH']);

        if ($this->testRepoPath !== '' && File::isDirectory($this->testRepoPath)) {
            $this->removeDir($this->testRepoPath);
        }
    }

    protected function projectUrl(): string
    {
        return '/p/'.$this->testProjectSlug;
    }

    private function runShell(string $command): string
    {
        $output = [];
        $code = 0;
        exec('cd '.escapeshellarg($this->testRepoPath)." && {$command} 2>&1", $output, $code);

        if ($code !== 0) {
            throw new \RuntimeException("Shell command failed (exit {$code}): {$command}\n".implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private function removeDir(string $dir): void
    {
        File::deleteDirectory($dir);
    }
}
