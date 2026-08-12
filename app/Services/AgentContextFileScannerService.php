<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AgentContextFile;
use App\Enums\AgentContextFileKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class AgentContextFileScannerService
{
    /**
     * Cap recursion depth when walking for untracked candidates, mirroring
     * ExternalFilesService::MAX_DEPTH. Without it a pathologically deep tree
     * could exhaust the PHP recursion stack on the synchronous Context-page scan.
     */
    private const MAX_SCAN_DEPTH = 8;

    public function __construct(
        private readonly GitProcessService $git,
        private readonly GitDiffService $gitDiffService,
    ) {}

    /**
     * Discover every agent-context file inside $repoPath, ordered by path —
     * CLAUDE.md / AGENTS.md plus the per-tool rule directories enumerated in
     * AgentContextFileKind (Cursor, Copilot, Windsurf, Cline, `.claude/`).
     *
     * Tracked entries come from `git ls-files`; untracked candidates are walked
     * from disk and filtered through `git check-ignore` in a single batch. Skip
     * dirs come from config('rfa.context_scan_skip_dirs') and may be augmented
     * via $extraSkipDirs (used by tests).
     *
     * @param  array<int, string>  $extraSkipDirs
     * @return array<int, AgentContextFile>
     */
    public function scan(string $repoPath, array $extraSkipDirs = []): array
    {
        $skipDirs = array_values(array_unique(array_merge(
            (array) config('rfa.context_scan_skip_dirs', []),
            $extraSkipDirs,
        )));

        $tracked = $this->discoverTracked($repoPath, $skipDirs);
        $untracked = $this->discoverUntracked($repoPath, $skipDirs, array_keys($tracked));

        // Symlink dedupe is keyed by `realpath()` of the absolute path. We
        // resolve both symlinked AND non-symlink entries so the keys agree on
        // platforms (notably macOS) where `/var` ≠ `/private/var` for the raw
        // path but realpath() collapses to the canonical form.
        //
        // When two paths collide on the same realpath we keep the canonical
        // entry: prefer the non-symlink, then the shorter path. That way the
        // tree shows the actual file rather than its mirror.
        $byRealpath = [];
        foreach ([...array_values($tracked), ...$untracked] as $file) {
            $key = realpath($file->absolutePath) ?: $file->absolutePath;
            $existing = $byRealpath[$key] ?? null;

            if ($existing === null || $this->preferOver($file, $existing)) {
                $byRealpath[$key] = $file;
            }
        }

        $results = array_values($byRealpath);
        usort($results, fn (AgentContextFile $a, AgentContextFile $b) => strcmp($a->path, $b->path));

        return $results;
    }

    /**
     * @param  array<int, string>  $skipDirs
     * @return array<string, AgentContextFile> keyed by repo-relative path
     */
    private function discoverTracked(string $repoPath, array $skipDirs): array
    {
        $args = ['ls-files', '-z', ...AgentContextFileKind::gitPathspecs()];

        $output = rescue(
            fn (): string => $this->git->run($repoPath, $args),
            rescue: null,
            report: false,
        );

        if ($output === null) {
            return [];
        }

        /** @var array<string, AgentContextFileKind> $kindsByPath */
        $kindsByPath = collect($this->splitNullDelimited($output))
            ->reject(fn (string $p): bool => $this->isSkipped($p, $skipDirs))
            ->mapWithKeys(fn (string $p): array => [$p => AgentContextFileKind::fromPath($p)])
            ->filter(fn (?AgentContextFileKind $kind): bool => $kind !== null)
            ->all();

        if ($kindsByPath === []) {
            return [];
        }

        $datesByPath = $this->resolveGitDates($repoPath, array_keys($kindsByPath));

        $entries = [];
        foreach ($kindsByPath as $relPath => $kind) {
            $absolute = $repoPath.'/'.$relPath;
            $isSymlink = is_link($absolute);
            $symlinkTarget = $isSymlink ? readlink($absolute) : null;
            [$createdAt, $lastEditedAt] = $datesByPath[$relPath] ?? [null, null];

            $entries[$relPath] = new AgentContextFile(
                path: $relPath,
                absolutePath: $absolute,
                kind: $kind,
                isTracked: true,
                isSymlink: $isSymlink,
                symlinkTarget: $symlinkTarget !== false ? $symlinkTarget : null,
                createdAt: $createdAt,
                lastEditedAt: $lastEditedAt,
                lineCount: $this->gitDiffService->countLinesInFile($absolute),
            );
        }

        return $entries;
    }

    /**
     * Walk the filesystem for agent-context files outside the tracked set,
     * batch-filter via `git check-ignore --stdin`, return whatever survives.
     *
     * @param  array<int, string>  $skipDirs
     * @param  array<int, string>  $trackedPaths
     * @return array<int, AgentContextFile>
     */
    private function discoverUntracked(string $repoPath, array $skipDirs, array $trackedPaths): array
    {
        $candidates = [];
        $trackedSet = array_flip($trackedPaths);

        $this->walkForCandidates($repoPath, '', $skipDirs, $trackedSet, $candidates);

        if ($candidates === []) {
            return [];
        }

        $ignored = $this->batchCheckIgnore($repoPath, array_keys($candidates));

        $entries = [];
        foreach ($candidates as $relPath => $kind) {
            if (isset($ignored[$relPath])) {
                continue;
            }

            $absolute = $repoPath.'/'.$relPath;
            $isSymlink = is_link($absolute);
            $symlinkTarget = $isSymlink ? readlink($absolute) : null;
            $mtime = File::isFile($absolute) ? File::lastModified($absolute) : null;

            $entries[] = new AgentContextFile(
                path: $relPath,
                absolutePath: $absolute,
                kind: $kind,
                isTracked: false,
                isSymlink: $isSymlink,
                symlinkTarget: $symlinkTarget !== false ? $symlinkTarget : null,
                createdAt: null,
                lastEditedAt: $mtime !== null ? CarbonImmutable::createFromTimestamp($mtime) : null,
                lineCount: $this->gitDiffService->countLinesInFile($absolute),
            );
        }

        return $entries;
    }

    /**
     * @param  array<int, string>  $skipDirs
     * @param  array<string, int>  $trackedSet  Flipped paths for O(1) skip lookup.
     * @param  array<string, AgentContextFileKind>  $candidates  Mutated in place.
     */
    private function walkForCandidates(string $repoPath, string $relDir, array $skipDirs, array $trackedSet, array &$candidates, int $depth = 0): void
    {
        if ($depth > self::MAX_SCAN_DEPTH) {
            return;
        }

        $absoluteDir = $relDir === '' ? $repoPath : $repoPath.'/'.$relDir;

        if (! File::isDirectory($absoluteDir)) {
            return;
        }

        // is_link before is_dir avoids descending into symlinked dirs (which
        // can loop or escape the repo). We still match symlinked files below.
        if ($relDir !== '' && is_link($absoluteDir)) {
            return;
        }

        $handle = @opendir($absoluteDir);
        if ($handle === false) {
            return;
        }

        try {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relPath = $relDir === '' ? $entry : $relDir.'/'.$entry;

                if ($this->isSkipped($relPath, $skipDirs)) {
                    continue;
                }

                $absolutePath = $absoluteDir.'/'.$entry;

                if (is_dir($absolutePath) && ! is_link($absolutePath)) {
                    $this->walkForCandidates($repoPath, $relPath, $skipDirs, $trackedSet, $candidates, $depth + 1);

                    continue;
                }

                if (isset($trackedSet[$relPath])) {
                    continue;
                }

                $kind = AgentContextFileKind::fromPath($relPath);
                if ($kind === null) {
                    continue;
                }

                $candidates[$relPath] = $kind;
            }
        } finally {
            closedir($handle);
        }
    }

    /**
     * One shell-out for the whole list. Returns a set of repo-relative paths
     * that ARE ignored (so callers can skip them).
     *
     * @param  array<int, string>  $candidates
     * @return array<string, true>
     */
    private function batchCheckIgnore(string $repoPath, array $candidates): array
    {
        $process = new Process([
            'git', '-c', 'core.quotepath=false', '-C', $repoPath,
            'check-ignore', '--stdin', '-z',
        ]);
        $process->setTimeout(30);
        $process->setInput(implode("\0", $candidates));
        $process->run();

        // git check-ignore exits 0 when at least one path is ignored, 1 when
        // none are, 128 on hard failure. Treat 0 and 1 as success.
        $exit = $process->getExitCode();
        if ($exit !== 0 && $exit !== 1) {
            return [];
        }

        $ignored = [];
        foreach ($this->splitNullDelimited($process->getOutput()) as $path) {
            $ignored[$path] = true;
        }

        return $ignored;
    }

    /**
     * Resolve created/last-edited dates for every tracked path in a single
     * git log call. Drops `--follow` (which only accepts one pathspec) so we
     * miss pre-rename history — acceptable trade for CLAUDE.md / AGENTS.md
     * which rarely move, in exchange for one shell-out instead of N.
     *
     * @param  array<int, string>  $relPaths
     * @return array<string, array{0: ?CarbonImmutable, 1: ?CarbonImmutable}>
     */
    private function resolveGitDates(string $repoPath, array $relPaths): array
    {
        $output = rescue(
            fn (): string => $this->git->run($repoPath, [
                'log', '-z', '--format=COMMIT %aI', '--name-only',
                '--', ...$relPaths,
            ]),
            rescue: null,
            report: false,
        );

        if ($output === null) {
            return [];
        }

        // With -z, every field (commit header + each --name-only path) is
        // null-delimited with no inter-commit separator. Walk the token
        // stream and re-attribute paths to the most recent COMMIT header.
        $tokens = $this->splitNullDelimited($output);
        $dates = [];
        $currentDate = null;

        foreach ($tokens as $token) {
            // git --format ends each header with a literal newline before the
            // first --name-only path; strip leading whitespace per token.
            $token = ltrim($token, "\n");

            if (str_starts_with($token, 'COMMIT ')) {
                $currentDate = substr($token, 7);

                continue;
            }

            if ($currentDate === null || $token === '') {
                continue;
            }

            // Commits arrive newest-first; first hit is lastEditedAt,
            // last hit becomes createdAt by overwriting on each pass.
            $dates[$token][0] ??= $currentDate;
            $dates[$token][1] = $currentDate;
        }

        return collect($dates)
            ->map(fn (array $pair): array => [
                $this->parseGitDate($pair[1]),
                $this->parseGitDate($pair[0]),
            ])
            ->all();
    }

    /**
     * Parse a git author-date token defensively. An empty token must become null
     * (not `now()`, which CarbonImmutable::parse('') silently returns and which
     * would mislabel the file as edited just now), and a non-empty-but-unparseable
     * token must not throw — the parse runs outside the rescue() around the git
     * call, so an uncaught InvalidFormatException here would crash the whole
     * Context page render.
     */
    private function parseGitDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return rescue(
            fn (): CarbonImmutable => CarbonImmutable::parse($value),
            rescue: null,
            report: false,
        );
    }

    private function preferOver(AgentContextFile $candidate, AgentContextFile $current): bool
    {
        if ($candidate->isSymlink !== $current->isSymlink) {
            return ! $candidate->isSymlink;
        }

        $candidateLen = strlen($candidate->path);
        $currentLen = strlen($current->path);

        if ($candidateLen !== $currentLen) {
            return $candidateLen < $currentLen;
        }

        return strcmp($candidate->path, $current->path) < 0;
    }

    /** @param array<int, string> $skipDirs */
    private function isSkipped(string $relPath, array $skipDirs): bool
    {
        foreach ($skipDirs as $skip) {
            if ($skip === '') {
                continue;
            }

            if ($relPath === $skip || str_starts_with($relPath, $skip.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, string> */
    private function splitNullDelimited(string $output): array
    {
        return array_values(array_filter(
            explode("\0", $output),
            fn (string $piece): bool => $piece !== '',
        ));
    }
}
