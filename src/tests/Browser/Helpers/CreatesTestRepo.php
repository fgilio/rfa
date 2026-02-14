<?php

namespace Tests\Browser\Helpers;

trait CreatesTestRepo
{
    protected string $testRepoPath = '';

    protected function setUpTestRepo(): void
    {
        $this->testRepoPath = sys_get_temp_dir().'/rfa_browser_'.uniqid();
        mkdir($this->testRepoPath, 0755, true);

        // Initial tracked files
        file_put_contents($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet($name) {',
            '    return "Hello, " . $name;',
            '}',
            '',
        ]));

        file_put_contents($this->testRepoPath.'/config.php', implode("\n", [
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
        file_put_contents($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet(string $name): string {',
            '    return "Hello, {$name}!";',
            '}',
            '',
        ]));

        // Add new untracked file
        file_put_contents($this->testRepoPath.'/utils.php', implode("\n", [
            '<?php',
            'function formatDate($date) {',
            "    return date('Y-m-d', strtotime(\$date));",
            '}',
            '',
        ]));

        // Delete config.php
        unlink($this->testRepoPath.'/config.php');

        // Point app at this repo via env var (shared process with amphp server)
        $_ENV['RFA_REPO_PATH'] = $this->testRepoPath;
    }

    protected function setUpEmptyTestRepo(): void
    {
        $this->testRepoPath = sys_get_temp_dir().'/rfa_browser_'.uniqid();
        mkdir($this->testRepoPath, 0755, true);

        file_put_contents($this->testRepoPath.'/README.md', "# Test\n");

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

        $_ENV['RFA_REPO_PATH'] = $this->testRepoPath;
    }

    protected function tearDownTestRepo(): void
    {
        unset($_ENV['RFA_REPO_PATH']);

        if ($this->testRepoPath !== '' && is_dir($this->testRepoPath)) {
            $this->removeDir($this->testRepoPath);
        }
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
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
