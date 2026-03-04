<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BranchEntry;
use App\DTOs\CommitEntry;
use App\DTOs\DiffTarget;
use App\Exceptions\GitCommandException;
use Illuminate\Support\Facades\File;

class GitMetadataService
{
    public function __construct(
        private readonly GitProcessService $git,
    ) {}

    public function resolveGlobalExcludesFile(string $repoPath): ?string
    {
        try {
            $raw = trim($this->git->run($repoPath, ['config', '--global', 'core.excludesFile']));
        } catch (GitCommandException) {
            return null;
        }

        if ($raw === '') {
            return null;
        }

        // Expand ~ to HOME
        if (str_starts_with($raw, '~/')) {
            $home = $_SERVER['HOME'] ?? getenv('HOME');
            if ($home === false || $home === '') {
                return null;
            }
            $raw = $home.substr($raw, 1);
        }

        $resolved = realpath($raw);

        return $resolved !== false && File::isFile($resolved) ? $resolved : null;
    }

    public function getTopLevel(string $directory): string
    {
        return trim($this->git->run($directory, ['rev-parse', '--show-toplevel']));
    }

    public function getGitCommonDir(string $directory): string
    {
        $raw = trim($this->git->run($directory, ['rev-parse', '--git-common-dir']));

        if ($raw === '' || $raw === '.git') {
            return '';
        }

        // git may return relative path - resolve it
        if (! str_starts_with($raw, '/')) {
            $raw = $directory.'/'.$raw;
        }

        return (string) realpath($raw);
    }

    public function getGitDir(string $directory): string
    {
        $raw = trim($this->git->run($directory, ['rev-parse', '--git-dir']));

        if ($raw === '') {
            return '';
        }

        if (! str_starts_with($raw, '/')) {
            $raw = $directory.'/'.$raw;
        }

        return (string) realpath($raw);
    }

    public function getCurrentBranch(string $directory): string
    {
        return trim($this->git->run($directory, ['rev-parse', '--abbrev-ref', 'HEAD']));
    }

    public function getFileContent(string $repoPath, string $path, string $ref = DiffTarget::WORKING_CONTEXT): ?string
    {
        if ($ref === DiffTarget::WORKING_CONTEXT) {
            $fullPath = $repoPath.'/'.$path;

            if (! File::isFile($fullPath)) {
                return null;
            }

            return File::get($fullPath);
        }

        // Normalize legacy lowercase 'head' from image URLs
        if ($ref === 'head') {
            $ref = 'HEAD';
        }

        try {
            return $this->git->run($repoPath, ['show', $ref.':'.$path]);
        } catch (GitCommandException) {
            return null;
        }
    }

    public function resolveRef(string $repoPath, string $ref): ?string
    {
        if (str_starts_with($ref, '-')) {
            return null;
        }

        try {
            $resolved = trim($this->git->run($repoPath, ['rev-parse', '--verify', $ref.'^{commit}']));

            return $resolved !== '' ? $resolved : null;
        } catch (GitCommandException) {
            return null;
        }
    }

    /** @return string[] */
    public function getCommitParents(string $repoPath, string $hash): array
    {
        try {
            $output = trim($this->git->run($repoPath, ['rev-parse', $hash.'^@']));

            return $output !== '' ? explode("\n", $output) : [];
        } catch (GitCommandException) {
            return [];
        }
    }

    public function getChildCommit(string $repoPath, string $hash): ?string
    {
        try {
            $output = trim($this->git->run($repoPath, [
                'log', '--ancestry-path', '--format=%H', '--reverse', '-1', $hash.'..HEAD',
            ]));

            return $output !== '' ? $output : null;
        } catch (GitCommandException) {
            return null;
        }
    }

    /**
     * @return array{local: BranchEntry[], remote: BranchEntry[]}
     */
    public function getBranches(string $repoPath): array
    {
        $localOutput = $this->git->run($repoPath, ['branch', '--list', '--no-color']);
        $local = [];

        foreach (array_filter(explode("\n", $localOutput)) as $line) {
            $isCurrent = str_starts_with($line, '* ');
            $name = trim(ltrim($line, '* '));

            if ($name === '' || str_starts_with($name, '(HEAD detached')) {
                continue;
            }

            $local[] = new BranchEntry(
                name: $name,
                isCurrent: $isCurrent,
                isRemote: false,
            );
        }

        $remote = [];

        try {
            $remoteOutput = $this->git->run($repoPath, ['branch', '--remotes', '--no-color']);

            foreach (array_filter(explode("\n", $remoteOutput)) as $line) {
                $name = trim($line);

                if ($name === '' || str_contains($name, '->')) {
                    continue;
                }

                $remoteName = str_contains($name, '/') ? substr($name, 0, (int) strpos($name, '/')) : null;

                $remote[] = new BranchEntry(
                    name: $name,
                    isCurrent: false,
                    isRemote: true,
                    remote: $remoteName,
                );
            }
        } catch (GitCommandException) {
            // No remotes configured - ignore
        }

        return ['local' => $local, 'remote' => $remote];
    }

    /**
     * @return CommitEntry[]
     */
    public function getCommitLog(string $repoPath, int $limit = 50, int $offset = 0, ?string $branch = null): array
    {
        $args = ['log', "--format=%H\x1e%h\x1e%s\x1e%an\x1e%ar\x1e%aI", "--skip={$offset}", '-n', (string) $limit];

        if ($branch !== null && $branch !== '' && ! str_starts_with($branch, '-')) {
            $args[] = $branch;
        }

        try {
            $output = $this->git->run($repoPath, $args);
        } catch (GitCommandException) {
            return [];
        }

        $entries = [];

        foreach (array_filter(explode("\n", trim($output))) as $line) {
            $parts = explode("\x1e", $line);

            if (count($parts) < 6) {
                continue;
            }

            $entries[] = new CommitEntry(
                hash: $parts[0],
                shortHash: $parts[1],
                message: $parts[2],
                author: $parts[3],
                relativeDate: $parts[4],
                date: $parts[5],
            );
        }

        return $entries;
    }
}
