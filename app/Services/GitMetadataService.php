<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\BranchEntry;
use App\DTOs\CommitEntry;
use App\Enums\GitRef;
use App\Exceptions\GitCommandException;
use App\Support\PathGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class GitMetadataService
{
    public function __construct(
        private readonly GitProcessService $git,
    ) {}

    public function resolveGlobalExcludesFile(string $repoPath): ?string
    {
        try {
            $raw = trim($this->git->run($repoPath, ['config', '--global', 'core.excludesFile']));
        } catch (GitCommandException $e) {
            Log::warning('git.excludes_file.resolve_failed', [
                'reason' => 'global_excludes_file_resolve_failed',
                'exit_code' => $e->exitCode,
            ]);

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

    /**
     * Best-effort guess at the branch a review should diff against: a local
     * `main`, else a local `master`. Favours `main` when both exist — it's the
     * modern default, and the user can change it if the repo prefers otherwise.
     * Returns null when neither is present so callers leave the base unset
     * rather than committing to an arbitrary branch.
     */
    public function detectDefaultBaseBranch(string $directory): ?string
    {
        foreach (['main', 'master'] as $candidate) {
            $exists = $this->branchExists($directory, $candidate);

            if ($exists === true) {
                return $candidate;
            }

            // An indeterminate probe (timeout, lock) isn't a confirmed absence,
            // so don't fall through to a lower-priority candidate and risk
            // seeding `master` while `main` may well exist. Leave the base unset.
            if ($exists === null) {
                return null;
            }
        }

        return null;
    }

    /**
     * Read the URL configured for `origin`. Returns null when no origin is set
     * (e.g. repos with no remote, or remotes under a different name).
     */
    public function getRemoteUrl(string $directory, string $remoteName = 'origin'): ?string
    {
        $url = rescue(
            fn (): string => trim($this->git->run($directory, ['config', '--get', 'remote.'.$remoteName.'.url'])),
            rescue: '',
            report: false,
        );

        return $url === '' ? null : $url;
    }

    public function getHeadSha(string $directory): string
    {
        return trim($this->git->run($directory, ['rev-parse', 'HEAD']));
    }

    /**
     * Whether `$branch` exists as a local ref.
     *
     * Tri-state so callers can tell a confirmed absence apart from a probe that
     * could not complete: `true` exists, `false` is a confirmed absence (git
     * reported the ref as unknown), `null` is indeterminate (timeout, lock, or
     * any other git failure). Collapsing every error to `false` would let a
     * transient git hiccup read as a deletion — and downstream that can silently
     * retarget a review away from a branch that is still there.
     */
    public function branchExists(string $directory, string $branch): ?bool
    {
        if ($branch === '' || str_starts_with($branch, '-')) {
            return false;
        }

        try {
            $this->git->run($directory, ['rev-parse', '--verify', '--quiet', 'refs/heads/'.$branch]);

            return true;
        } catch (GitCommandException $e) {
            // `rev-parse --verify --quiet` exits 1 with no output for an unknown
            // ref; any other exit code (e.g. 128 for "not a git repository") is a
            // failure we can't read as a definitive absence.
            return $e->exitCode === 1 ? false : null;
        } catch (\Throwable) {
            // Timeout / process error: we genuinely don't know.
            return null;
        }
    }

    public function getFileContent(string $repoPath, string $path, string $ref = GitRef::Working->value): ?string
    {
        if ($this->looksLikeFlag($ref) || ! PathGuard::isRelative($path)) {
            return null;
        }

        if ($ref === GitRef::Working->value) {
            $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

            if ($fullPath === null || ! File::isFile($fullPath)) {
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
        } catch (GitCommandException $e) {
            Log::warning('git.file_content.read_failed', [
                'reason' => 'file_content_read_failed',
                'ref' => $ref,
                'path' => $path,
                'exit_code' => $e->exitCode,
            ]);

            return null;
        }
    }

    public function resolveRef(string $repoPath, string $ref): ?string
    {
        if ($this->looksLikeFlag($ref)) {
            return null;
        }

        try {
            $resolved = trim($this->git->run($repoPath, ['rev-parse', '--verify', $ref.'^{commit}']));

            return $resolved !== '' ? $resolved : null;
        } catch (GitCommandException $e) {
            Log::warning('git.ref.resolve_failed', [
                'reason' => 'git_ref_resolve_failed',
                'ref' => $ref,
                'exit_code' => $e->exitCode,
            ]);

            return null;
        }
    }

    public function resolveRefExpression(string $repoPath, string $ref): string
    {
        $isParentRef = str_ends_with($ref, '^');
        $baseRef = $isParentRef ? substr($ref, 0, -1) : $ref;
        $resolved = $baseRef !== '' ? $this->resolveRef($repoPath, $baseRef) : null;

        return ($resolved ?? $baseRef).($isParentRef ? '^' : '');
    }

    /** @return string[] */
    public function getCommitParents(string $repoPath, string $hash): array
    {
        try {
            $output = trim($this->git->run($repoPath, ['rev-parse', $hash.'^@']));

            return $output !== '' ? explode("\n", $output) : [];
        } catch (GitCommandException $e) {
            Log::warning('git.commit_parents.read_failed', [
                'reason' => 'commit_parents_read_failed',
                'hash' => $hash,
                'exit_code' => $e->exitCode,
            ]);

            return [];
        }
    }

    /**
     * True when $ref resolves to a commit with no parents (the repo's root).
     * Uses a single `rev-list --parents -n 1` call instead of separate resolve + parent lookups.
     */
    public function isRootCommit(string $repoPath, string $ref): bool
    {
        if ($this->looksLikeFlag($ref)) {
            return false;
        }

        try {
            $line = trim($this->git->run($repoPath, ['rev-list', '--parents', '-n', '1', $ref]));
        } catch (GitCommandException $e) {
            Log::warning('git.root_commit.check_failed', [
                'reason' => 'root_commit_check_failed',
                'ref' => $ref,
                'exit_code' => $e->exitCode,
            ]);

            return false;
        }

        // `rev-list --parents -n 1` prints: "<hash> <parent1> <parent2> ..."
        // A root commit emits exactly one token (its own hash, no parents).
        return $line !== '' && ! str_contains($line, ' ');
    }

    /**
     * Compute the best common ancestor (merge-base) of two refs. Returns null
     * when either ref is unknown or the histories don't intersect (orphan
     * branches).
     */
    public function getMergeBase(string $repoPath, string $a, string $b): ?string
    {
        if ($this->looksLikeFlag($a) || $this->looksLikeFlag($b)) {
            return null;
        }

        // Unrelated histories or missing refs are both non-fatal here.
        $output = rescue(
            fn (): string => trim($this->git->run($repoPath, ['merge-base', $a, $b])),
            rescue: '',
            report: false,
        );

        return $output !== '' ? $output : null;
    }

    public function getChildCommit(string $repoPath, string $hash): ?string
    {
        try {
            $output = trim($this->git->run($repoPath, [
                'log', '--ancestry-path', '--format=%H', '--reverse', '-1', $hash.'..HEAD',
            ]));

            return $output !== '' ? $output : null;
        } catch (GitCommandException $e) {
            Log::warning('git.child_commit.read_failed', [
                'reason' => 'child_commit_read_failed',
                'hash' => $hash,
                'exit_code' => $e->exitCode,
            ]);

            return null;
        }
    }

    /**
     * @return array{local: BranchEntry[], remote: BranchEntry[]}
     */
    public function getBranches(string $repoPath): array
    {
        $localOutput = $this->git->run($repoPath, ['branch', '--list', '--no-color']);
        $local = collect(explode("\n", $localOutput))
            ->filter()
            ->map(fn (string $line): BranchEntry => new BranchEntry(
                // `git branch --list` uses a fixed two-column marker: `* ` for the
                // current branch, `+ ` for a branch checked out in a linked worktree,
                // two spaces otherwise. Strip those columns rather than only the `* `,
                // so a worktree branch doesn't become an unresolvable "+ name" ref.
                name: trim(substr($line, 2)),
                isCurrent: str_starts_with($line, '* '),
                isRemote: false,
            ))
            ->reject(fn (BranchEntry $branch): bool => $branch->name === '' || str_starts_with($branch->name, '(HEAD detached'))
            ->values()
            ->all();

        $remoteOutput = rescue(
            fn (): string => $this->git->run($repoPath, ['branch', '--remotes', '--no-color']),
            rescue: '',
            report: false,
        );

        $remote = collect(explode("\n", $remoteOutput))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->reject(fn (string $name): bool => str_contains($name, '->'))
            ->map(function (string $name): BranchEntry {
                $remoteName = str_contains($name, '/')
                    ? substr($name, 0, (int) strpos($name, '/'))
                    : null;

                return new BranchEntry(
                    name: $name,
                    isCurrent: false,
                    isRemote: true,
                    remote: $remoteName,
                );
            })
            ->values()
            ->all();

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
        } catch (GitCommandException $e) {
            Log::warning('git.commit_log.read_failed', [
                'reason' => 'commit_log_read_failed',
                'exit_code' => $e->exitCode,
            ]);

            return [];
        }

        return collect(explode("\n", trim($output)))
            ->filter()
            ->map(fn (string $line): array => explode("\x1e", $line))
            ->filter(fn (array $parts): bool => count($parts) >= 6)
            ->map(fn (array $parts): CommitEntry => new CommitEntry(
                hash: $parts[0],
                shortHash: $parts[1],
                message: $parts[2],
                author: $parts[3],
                relativeDate: $parts[4],
                date: $parts[5],
            ))
            ->values()
            ->all();
    }

    private function looksLikeFlag(string $ref): bool
    {
        return str_starts_with($ref, '-');
    }
}
