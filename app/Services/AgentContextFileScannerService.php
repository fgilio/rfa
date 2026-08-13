<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\AgentContextFile;
use App\Enums\AgentContextFileKind;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

class AgentContextFileScannerService
{
    public function __construct(
        private readonly GitProcessService $git,
        private readonly GitDiffService $gitDiffService,
    ) {}

    /**
     * Discover every agent context file inside $repoPath, ordered by path.
     * See AgentContextFileKind for the conventions recognised.
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
        $untracked = $this->discoverUntracked($repoPath, $skipDirs);

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
            ->whereNotNull()
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
     * Discover untracked agent-context files while letting Git exclude ignored
     * directories before it traverses them.
     *
     * @param  array<int, string>  $skipDirs
     * @return array<int, AgentContextFile>
     */
    private function discoverUntracked(string $repoPath, array $skipDirs): array
    {
        $output = rescue(
            fn (): string => $this->git->run($repoPath, [
                'ls-files', '--others', '--exclude-standard', '-z',
                '--', ...AgentContextFileKind::gitPathspecs(),
            ]),
            rescue: null,
            report: false,
        );

        if ($output === null) {
            return [];
        }

        /** @var array<string, AgentContextFileKind> $candidates */
        $candidates = collect($this->splitNullDelimited($output))
            ->reject(fn (string $path): bool => $this->isSkipped($path, $skipDirs))
            ->mapWithKeys(fn (string $path): array => [$path => AgentContextFileKind::fromPath($path)])
            ->whereNotNull()
            ->all();

        $entries = [];
        foreach ($candidates as $relPath => $kind) {
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
     * Resolve created/last-edited dates for every tracked path in a single
     * git log call. Drops `--follow` (which only accepts one pathspec) in
     * exchange for one shell-out instead of N: a rule file that was renamed
     * shows dates from its current path only.
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
