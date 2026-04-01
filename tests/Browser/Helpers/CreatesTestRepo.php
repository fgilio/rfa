<?php

namespace Tests\Browser\Helpers;

use App\Actions\RegisterProjectAction;
use Illuminate\Support\Facades\File;
use Pest\Browser\Api\PendingAwaitablePage;
use Tests\Helpers\InteractsWithTestRepositories;

trait CreatesTestRepo
{
    use InteractsWithTestRepositories;

    protected string $testRepoPath = '';

    protected string $testProjectSlug = '';

    /** @var list<string> */
    protected array $testRepoPaths = [];

    /** @var list<string> */
    protected array $testProjectSlugs = [];

    /** @var list<string> Full SHA hashes, oldest→newest */
    protected array $commitHashes = [];

    /** @var list<string> 7-char short hashes, oldest→newest */
    protected array $commitShortHashes = [];

    protected function setUpTestRepo(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

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

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Initial commit');

        // Verify HEAD exists
        $this->assertHeadExists();

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

        $this->registerTestProject($this->testRepoPath);
    }

    protected function setUpEmptyTestRepo(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

        File::put($this->testRepoPath.'/README.md', "# Test\n");

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Initial commit');

        // Verify HEAD exists
        $this->assertHeadExists();

        $this->registerTestProject($this->testRepoPath);
    }

    protected function setUpMultiHunkTestRepo(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

        // Create a 30-line file so modifying lines 1 and 30 produces 2 hunks with default context (3)
        $lines = array_map(fn ($i) => "line{$i}", range(1, 30));
        File::put($this->testRepoPath.'/multi.txt', implode("\n", $lines)."\n");

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Initial commit');

        $this->assertHeadExists();

        // Modify first and last lines to create 2 distant hunks
        $lines[0] = 'changed1';
        $lines[29] = 'changed30';
        File::put($this->testRepoPath.'/multi.txt', implode("\n", $lines)."\n");

        $this->registerTestProject($this->testRepoPath);
    }

    protected function setUpCommitHistoryRepo(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

        // Commit 1: initial hello.php
        File::put($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet($name) {',
            '    return "Hello, " . $name;',
            '}',
            '',
        ]));

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Add greet function');

        // Commit 2: modify hello.php (add type hints) + add utils.php
        File::put($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            'function greet(string $name): string {',
            '    return "Hello, {$name}!";',
            '}',
            '',
        ]));

        File::put($this->testRepoPath.'/utils.php', implode("\n", [
            '<?php',
            'function formatDate($date) {',
            "    return date('Y-m-d', strtotime(\$date));",
            '}',
            '',
        ]));

        $this->runShell(implode(' && ', [
            'git add -A',
            "git commit -m 'Add type hints and utils'",
        ]));

        // Commit 3: modify utils.php (change date format)
        File::put($this->testRepoPath.'/utils.php', implode("\n", [
            '<?php',
            'function formatDate($date) {',
            "    return date('d/m/Y', strtotime(\$date));",
            '}',
            '',
        ]));

        $this->runShell(implode(' && ', [
            'git add -A',
            "git commit -m 'Change date format to d/m/Y'",
        ]));

        // Collect commit hashes oldest→newest
        $log = trim($this->runShell('git log --reverse --format=%H'));
        $this->commitHashes = explode("\n", $log);
        $this->commitShortHashes = array_map(fn ($h) => substr($h, 0, 7), $this->commitHashes);

        $this->registerTestProject($this->testRepoPath);
    }

    protected function setUpCommitHistoryRepoWithWdChange(): void
    {
        $this->setUpCommitHistoryRepo();

        // Add uncommitted working directory change to hello.php
        File::put($this->testRepoPath.'/hello.php', implode("\n", [
            '<?php',
            '// Updated with WD change',
            'function greet(string $name): string {',
            '    return "Hello, {$name}!";',
            '}',
            '',
        ]));
    }

    protected function setUpCommitHistoryRepoWithEmptyCommit(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

        File::put($this->testRepoPath.'/README.md', "# Test\n");

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Initial commit');
        $this->runShell("git commit --allow-empty -m 'Empty commit'");

        $log = trim($this->runShell('git log --reverse --format=%H'));
        $this->commitHashes = explode("\n", $log);
        $this->commitShortHashes = array_map(fn ($h) => substr($h, 0, 7), $this->commitHashes);

        $this->registerTestProject($this->testRepoPath);
    }

    protected function setUpRegisteredProjects(array $names, bool $withWorkingTreeChanges = true): void
    {
        $this->testRepoPaths = [];
        $this->testProjectSlugs = [];

        foreach ($names as $name) {
            $path = $this->makeTempRepoPath("rfa_dashboard_{$name}_");

            File::put($path.'/README.md', "# {$name}\n");

            $this->initTestRepo($path);
            $this->commitTestRepo($path, 'Initial commit');

            if ($withWorkingTreeChanges) {
                File::put($path.'/README.md', "# {$name}\nchanged\n");
            }

            $slug = $this->registerTestProject($path);

            $this->testRepoPaths[] = $path;
            $this->testProjectSlugs[] = $slug;
        }
    }

    protected function setUpScrollableTestRepo(): void
    {
        $this->testRepoPath = $this->makeTempRepoPath();

        // 80 lines so the diff overflows any standard viewport
        $lines = array_map(fn ($i) => sprintf('line %03d: original content here', $i), range(1, 80));
        File::put($this->testRepoPath.'/scrollable.txt', implode("\n", $lines)."\n");

        $this->initTestRepo($this->testRepoPath);
        $this->commitTestRepo($this->testRepoPath, 'Initial commit');
        $this->assertHeadExists();

        // Modify every other line to produce a dense diff
        $modified = array_map(
            fn ($i) => $i % 2 === 0
                ? sprintf('line %03d: modified content here', $i)
                : sprintf('line %03d: original content here', $i),
            range(1, 80),
        );
        File::put($this->testRepoPath.'/scrollable.txt', implode("\n", $modified)."\n");

        $this->registerTestProject($this->testRepoPath);
    }

    protected function addLargeFile(string $name = 'large.txt', int $bytes = 600_000): void
    {
        File::put($this->testRepoPath.'/'.$name, str_repeat("line of content for large file\n", (int) ceil($bytes / 30)));
    }

    protected function pressGlobalKey(mixed $page, string $key, array $modifiers = []): void
    {
        $opts = json_encode(['key' => $key, 'bubbles' => true, 'cancelable' => true] + $modifiers);
        $page->script('document.dispatchEvent(new KeyboardEvent("keydown", '.$opts.'));');
    }

    protected function tearDownTrackedTestRepos(): void
    {
        $paths = array_values(array_unique(array_filter([
            $this->testRepoPath,
            ...$this->testRepoPaths,
        ])));

        usort($paths, fn (string $left, string $right) => strlen($right) <=> strlen($left));

        foreach ($paths as $path) {
            if (File::isDirectory($path)) {
                $this->removeDir($path);
            }
        }

        $this->testRepoPath = '';
        $this->testProjectSlug = '';
        $this->testRepoPaths = [];
        $this->testProjectSlugs = [];
    }

    protected function projectUrl(): string
    {
        return '/p/'.$this->testProjectSlug;
    }

    /** Visit the project page and wait for lazy-loaded diffs to finish loading. */
    protected function visitAndLoad(string $url): PendingAwaitablePage
    {
        $page = $this->visit($url);
        $page->waitForEvent('networkidle');

        return $page;
    }

    private function makeTempRepoPath(string $prefix = 'rfa_browser_'): string
    {
        return $this->createTempDirectory($prefix);
    }

    private function assertHeadExists(): void
    {
        $head = trim($this->runShell('git rev-parse HEAD'));

        if ($head === '' || str_contains($head, 'fatal')) {
            throw new \RuntimeException("Git setup failed: HEAD not established. Output: {$head}");
        }
    }

    private function registerTestProject(string $path): string
    {
        $project = app(RegisterProjectAction::class)->handle($path);

        if ($this->testRepoPath === $path) {
            $this->testProjectSlug = $project->slug;
        }

        return $project->slug;
    }

    private function runShell(string $command): string
    {
        return $this->runShellIn($this->testRepoPath, $command);
    }

    private function runShellIn(string $path, string $command): string
    {
        return $this->runTestRepoCommand($path, $command);
    }

    private function removeDir(string $dir): void
    {
        File::deleteDirectory($dir);
    }
}
