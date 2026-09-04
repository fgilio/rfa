<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\DTOs\ReviewConfig;
use App\Support\AnsiText;
use App\Support\PathGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;

class GitDiffService
{
    private const SYMLINK_MODE = '120000';

    private const STABLE_DIFF_OPTIONS = [
        '--no-ext-diff',
        '--find-renames',
        '--src-prefix=a/',
        '--dst-prefix=b/',
    ];

    public function __construct(
        private readonly GitProcessService $git,
        private readonly IgnoreService $ignoreService,
        ?ReviewConfigService $reviewConfigService = null,
    ) {
        $this->reviewConfigService = $reviewConfigService ?? new ReviewConfigService;
    }

    private readonly ReviewConfigService $reviewConfigService;

    /** @return FileListEntry[] */
    public function getFileList(string $repoPath, ?string $globalGitignorePath = null, ?DiffTarget $target = null, ?string $onlyPath = null): array
    {
        $target ??= DiffTarget::workingDirectory();

        if ($onlyPath !== null && ! PathGuard::isRelative($onlyPath)) {
            return [];
        }

        $ignoreRules = $this->ignoreService->rules($repoPath);
        $pathspecs = [$onlyPath ?? '.'];

        // Get status (M/A/D/R) for tracked changes
        $nameStatus = $this->git->run($repoPath, [
            ...$this->diffArgs($target, ['--name-status']),
            '--', ...$pathspecs,
        ]);

        // Get +/- line counts for tracked changes. Lockfiles are excluded via
        // pathspec so git never diffs them: `--numstat` computes real line
        // counts, which is expensive for exactly the huge generated files the
        // evaluator drops a moment later. Only the non-negatable lockfile set
        // goes here, so this cannot disagree with isPathExcluded().
        $numstat = $this->git->run($repoPath, [
            ...$this->diffArgs($target, ['--numstat']),
            '--', ...$pathspecs, ...($onlyPath === null ? $this->ignoreService->alwaysExcludePathspecs() : []),
        ]);

        // Parse name-status into [path => [status, oldPath]]
        $statusMap = [];
        foreach (array_filter(explode("\n", trim($nameStatus))) as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 2) {
                continue;
            }

            $statusCode = $parts[0];
            if (str_starts_with($statusCode, 'R')) {
                $statusMap[$parts[2]] = ['renamed', $parts[1]];
            } elseif ($statusCode === 'A') {
                $statusMap[$parts[1]] = ['added', null];
            } elseif ($statusCode === 'D') {
                $statusMap[$parts[1]] = ['deleted', null];
            } else {
                $statusMap[$parts[1]] = ['modified', null];
            }
        }

        // Parse numstat into [path => [additions, deletions, isBinary]]
        $statMap = [];
        foreach (array_filter(explode("\n", trim($numstat))) as $line) {
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 3) {
                continue;
            }

            // Binary files show "-" for additions/deletions
            $isBinary = $parts[0] === '-' && $parts[1] === '-';
            // For renames, numstat shows the new path (last tab-separated value)
            $path = $parts[2];
            // numstat renders renames two ways. With a common prefix/suffix it uses
            // the compact brace form `dir/{old => new}/file`; with none (e.g. a
            // repo-root rename) it emits a bare `old => new` with no braces. Resolve
            // both to the new path so the key matches name-status (`$parts[2]` there).
            if (str_contains($path, ' => ')) {
                if (preg_match('/\{.*? => (.*?)\}/', $path, $m) === 1) {
                    $path = str_replace($m[0], $m[1], $path);
                } else {
                    $path = substr($path, (int) strpos($path, ' => ') + 4);
                }
            }
            $statMap[$path] = [
                'additions' => $isBinary ? 0 : (int) $parts[0],
                'deletions' => $isBinary ? 0 : (int) $parts[1],
                'isBinary' => $isBinary,
            ];
        }

        $entries = collect($statusMap)
            ->reject(fn (array $entry, string $path): bool => $this->ignoreService->isPathExcluded($path, $ignoreRules))
            ->map(function (array $entry, string $path) use ($statMap, $target, $repoPath): FileListEntry {
                [$status, $oldPath] = $entry;

                $stats = $statMap[$path] ?? ['additions' => 0, 'deletions' => 0, 'isBinary' => false];
                $isBinary = $stats['isBinary'];

                if ($isBinary && $status === 'modified') {
                    $status = 'binary';
                }

                $symlinkTarget = $target->isWorkingDirectory()
                    ? $this->symlinkTarget($repoPath, $path)
                    : null;

                $isWorkingDir = $target->isWorkingDirectory();

                return new FileListEntry(
                    path: $path,
                    status: $status,
                    oldPath: $oldPath,
                    additions: $stats['additions'],
                    deletions: $stats['deletions'],
                    isBinary: $isBinary,
                    isUntracked: false,
                    lastModified: $isWorkingDir ? $this->getLastModified($repoPath, $path) : null,
                    isSymlink: $symlinkTarget !== null,
                    symlinkTarget: $symlinkTarget,
                    fileSize: $isWorkingDir ? $this->getHumanFileSize($repoPath, $path) : null,
                    mtime: $isWorkingDir ? $this->getRawMtime($repoPath, $path) : null,
                    byteSize: $isWorkingDir ? $this->getRawByteSize($repoPath, $path) : null,
                );
            })
            ->values()
            ->all();

        // Get untracked files only when comparing against working tree
        if ($target->isWorkingDirectory()) {
            $lsFilesArgs = ['ls-files', '--others', '--exclude-standard'];
            if ($globalGitignorePath !== null && File::isFile($globalGitignorePath)) {
                $lsFilesArgs[] = '--exclude-from='.$globalGitignorePath;
            }
            $lsFilesArgs[] = '--';
            array_push($lsFilesArgs, ...$pathspecs);
            $untrackedOutput = $this->git->run($repoPath, $lsFilesArgs);

            if (trim($untrackedOutput) !== '') {
                $untrackedFiles = array_filter(explode("\n", trim($untrackedOutput)));

                foreach ($untrackedFiles as $file) {
                    if ($this->ignoreService->isPathExcluded($file, $ignoreRules)) {
                        continue;
                    }

                    $symlinkTarget = $this->symlinkTarget($repoPath, $file);
                    if ($symlinkTarget !== null) {
                        $entries[] = new FileListEntry(
                            path: $file,
                            status: 'added',
                            oldPath: null,
                            additions: 1,
                            deletions: 0,
                            isBinary: false,
                            isUntracked: true,
                            lastModified: null,
                            isSymlink: true,
                            symlinkTarget: $symlinkTarget,
                        );

                        continue;
                    }

                    $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $file);

                    if ($fullPath === null || ! File::isFile($fullPath)) {
                        continue;
                    }

                    $isBinary = $this->isBinary($fullPath);

                    if ($isBinary) {
                        $entries[] = new FileListEntry(
                            path: $file,
                            status: 'added',
                            oldPath: null,
                            additions: 0,
                            deletions: 0,
                            isBinary: true,
                            isUntracked: true,
                            lastModified: $this->getLastModified($repoPath, $file),
                            fileSize: $this->getHumanFileSize($repoPath, $file),
                            mtime: $this->getRawMtime($repoPath, $file),
                            byteSize: $this->getRawByteSize($repoPath, $file),
                        );

                        continue;
                    }

                    $content = File::get($fullPath);
                    $lineCount = substr_count($content, "\n") + ($content !== '' && ! str_ends_with($content, "\n") ? 1 : 0);

                    $entries[] = new FileListEntry(
                        path: $file,
                        status: 'added',
                        oldPath: null,
                        additions: $lineCount,
                        deletions: 0,
                        isBinary: false,
                        isUntracked: true,
                        lastModified: $this->getLastModified($repoPath, $file),
                        mtime: $this->getRawMtime($repoPath, $file),
                        byteSize: $this->getRawByteSize($repoPath, $file),
                    );
                }
            }
        }

        return $entries;
    }

    public function getWorkingDirectoryFingerprint(string $repoPath, ?string $globalGitignorePath = null): string
    {
        return $this->getWorkingDirectoryStatus($repoPath, $globalGitignorePath)['fingerprint'];
    }

    /**
     * @return array{fingerprint: string, count: int}
     */
    public function getWorkingDirectoryStatus(string $repoPath, ?string $globalGitignorePath = null): array
    {
        $ignoreRules = $this->ignoreService->rules($repoPath);

        $nameStatus = $this->git->run($repoPath, [
            ...$this->diffArgs(DiffTarget::workingDirectory(), ['--name-status']),
            '--', '.',
        ]);

        $lsFilesArgs = ['ls-files', '--others', '--exclude-standard'];
        if ($globalGitignorePath !== null && File::isFile($globalGitignorePath)) {
            $lsFilesArgs[] = '--exclude-from='.$globalGitignorePath;
        }
        $untrackedOutput = $this->git->run($repoPath, $lsFilesArgs);

        $lines = array_filter([
            ...explode("\n", trim($nameStatus)),
            ...array_map(
                fn (string $f) => "?\t".$f,
                array_filter(explode("\n", trim($untrackedOutput))),
            ),
        ]);

        // The fingerprint must cover exactly the files getFileList() shows, so
        // tracked and untracked lines run through the same evaluator here.
        $lines = collect($lines)
            ->reject(fn (string $line): bool => $this->ignoreService->isPathExcluded($this->statusLinePath($line) ?? '', $ignoreRules))
            ->map(fn (string $line): string => $this->withWorkingTreeFileFingerprint($repoPath, $line))
            ->values()
            ->all();

        sort($lines);

        return [
            'fingerprint' => hash('xxh128', implode("\n", $lines)),
            'count' => count($lines),
        ];
    }

    public function fileDiffFingerprint(string $repoPath, string $path, ?DiffTarget $target = null): string
    {
        $target ??= DiffTarget::workingDirectory();

        if ($target->isImmutable()) {
            return '';
        }

        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

        if ($fullPath === null || ! File::isFile($fullPath)) {
            return '';
        }

        $fingerprint = hash_file('xxh128', $fullPath);

        return is_string($fingerprint) ? $fingerprint : '';
    }

    private function withWorkingTreeFileFingerprint(string $repoPath, string $line): string
    {
        // A deleted file has no on-disk content left to fingerprint.
        if (str_starts_with($line, "D\t")) {
            return $line;
        }

        $path = $this->statusLinePath($line);

        if ($path === null) {
            return $line;
        }

        $fingerprint = $this->fileDiffFingerprint($repoPath, $path);

        return $fingerprint === '' ? $line : "{$line}\t{$fingerprint}";
    }

    /** The path a status line is about: the destination for a rename or copy, the file itself otherwise. */
    private function statusLinePath(string $line): ?string
    {
        $parts = preg_split('/\t/', $line);
        $status = $parts[0] ?? '';

        if ($status === '') {
            return null;
        }

        return str_starts_with($status, 'R') || str_starts_with($status, 'C')
            ? ($parts[2] ?? null)
            : ($parts[1] ?? null);
    }

    public function getFileDiff(string $repoPath, string $path, bool $isUntracked = false, ?int $maxBytes = null, ?int $contextLines = null, ?DiffTarget $target = null, ?string $oldPath = null, bool $detectMovedLines = true): ?string
    {
        $target ??= DiffTarget::workingDirectory();
        $reviewConfig = $this->reviewConfigService->resolve();
        $maxBytes ??= $reviewConfig->diffMaxBytes;
        $contextLines ??= $reviewConfig->defaultContextLines;

        // Paths only reach git as pathspecs here, so a lexical traversal check
        // is the right guard. Requiring on-disk resolution would wrongly blank
        // commit-range diffs of files that no longer exist in the worktree or
        // that sit under a directory symlinked outside the repository.
        if (! PathGuard::isRelative($path)) {
            return '';
        }

        if ($oldPath !== null && ! PathGuard::isRelative($oldPath)) {
            return '';
        }

        if ($this->ignoreService->isPathExcluded($path, $this->ignoreService->rules($repoPath))) {
            return '';
        }

        if ($isUntracked && $target->isWorkingDirectory()) {
            return $this->buildUntrackedDiff($repoPath, $path, $maxBytes);
        }

        // Rename-detection only fires when both sides of the rename are within
        // the pathspec; pass the old path alongside so git can pair them.
        $renamePaths = $oldPath !== null && $oldPath !== $path ? [$oldPath] : [];

        $raw = $this->git->run($repoPath, [
            ...$this->diffArgs($target, ["--unified={$contextLines}", '--text'], detectMovedLines: $detectMovedLines, reviewConfig: $reviewConfig),
            '--', $path, ...$renamePaths,
        ]);

        // The size cap protects the parser and renderer from oversized diffs,
        // so measure the text that survives parsing: ANSI color codes added
        // for moved-line detection are stripped and must not count.
        if (strlen(AnsiText::strip($raw)) > $maxBytes) {
            return null;
        }

        return $raw;
    }

    private function buildUntrackedDiff(string $repoPath, string $path, int $maxBytes): ?string
    {
        $symlinkTarget = $this->symlinkTarget($repoPath, $path);
        if ($symlinkTarget !== null) {
            $mode = self::SYMLINK_MODE;

            return "diff --git a/{$path} b/{$path}\nnew file mode {$mode}\n--- /dev/null\n+++ b/{$path}\n@@ -0,0 +1 @@\n+{$symlinkTarget}\n\\ No newline at end of file\n";
        }

        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);
        if ($fullPath === null) {
            return '';
        }

        return $this->buildAddedFileDiff($fullPath, $path, $maxBytes);
    }

    /**
     * Build a unified diff for a brand-new file (`/dev/null` → contents). Used
     * for both untracked working-tree files and externally-mounted files; the
     * format mirrors `git diff` output for new files exactly.
     */
    public function buildAddedFileDiff(string $absolutePath, string $diffPath, int $maxBytes): ?string
    {
        if (! File::isFile($absolutePath)) {
            return '';
        }

        if ($this->isBinary($absolutePath)) {
            return "diff --git a/{$diffPath} b/{$diffPath}\nnew file mode 100644\nBinary files /dev/null and b/{$diffPath} differ\n";
        }

        $size = File::size($absolutePath);
        if ($size > $maxBytes) {
            return null;
        }

        $content = File::get($absolutePath);
        if ($content === '') {
            return "diff --git a/{$diffPath} b/{$diffPath}\nnew file mode 100644\n";
        }

        $lines = explode("\n", $content);

        if (end($lines) === '') {
            array_pop($lines);
        }

        $diff = "diff --git a/{$diffPath} b/{$diffPath}\n";
        $diff .= "new file mode 100644\n";
        $diff .= "--- /dev/null\n";
        $diff .= "+++ b/{$diffPath}\n";
        $diff .= '@@ -0,0 +1,'.count($lines)." @@\n";
        $diff .= '+'.implode("\n+", $lines)."\n";

        return $diff;
    }

    public function getNewFileLineCount(string $repoPath, string $path, ?DiffTarget $target = null): ?int
    {
        $target ??= DiffTarget::workingDirectory();

        if ($target->isWorkingDirectory()) {
            $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

            return $fullPath === null ? null : $this->countLinesInFile($fullPath);
        }

        $content = rescue(
            fn (): string => $this->git->run($repoPath, ['show', $target->to().':'.$path]),
            rescue: null,
            report: false,
        );

        if ($content === null) {
            return null;
        }

        return $this->countLinesInString($content);
    }

    /** Line count for a file on disk, or null if the file is missing. */
    public function countLinesInFile(string $absolutePath): ?int
    {
        if (! File::isFile($absolutePath)) {
            return null;
        }

        return $this->countLinesInString(File::get($absolutePath));
    }

    private function countLinesInString(string $content): int
    {
        if ($content === '') {
            return 0;
        }

        return substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1);
    }

    private function getLastModified(string $repoPath, string $path): ?string
    {
        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

        if ($fullPath === null || ! File::isFile($fullPath)) {
            return null;
        }

        return Carbon::createFromTimestamp(File::lastModified($fullPath))->diffForHumans(short: true);
    }

    private function getHumanFileSize(string $repoPath, string $path): ?string
    {
        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

        if ($fullPath === null || ! File::isFile($fullPath)) {
            return null;
        }

        return Number::fileSize(File::size($fullPath), precision: 1);
    }

    private function getRawMtime(string $repoPath, string $path): ?int
    {
        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

        return $fullPath !== null && File::isFile($fullPath) ? File::lastModified($fullPath) : null;
    }

    private function getRawByteSize(string $repoPath, string $path): ?int
    {
        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path);

        return $fullPath !== null && File::isFile($fullPath) ? File::size($fullPath) : null;
    }

    private function symlinkTarget(string $repoPath, string $path): ?string
    {
        $fullPath = PathGuard::tryResolveWithinRepo($repoPath, $path, followLeaf: false);

        if ($fullPath === null) {
            return null;
        }

        return is_link($fullPath) ? readlink($fullPath) : null;
    }

    private function isBinary(string $path): bool
    {
        $chunk = substr(File::get($path), 0, 8192);

        return str_contains($chunk, "\0");
    }

    /**
     * @param  list<string>  $options
     * @return list<string>
     */
    private function diffArgs(DiffTarget $target, array $options = [], bool $detectMovedLines = false, ?ReviewConfig $reviewConfig = null): array
    {
        $reviewConfig ??= $this->reviewConfigService->resolve();
        $colorOptions = $detectMovedLines && $reviewConfig->movedLineDetection
            ? ['--color=always', "--color-moved={$reviewConfig->movedLineMode}"]
            : ['--no-color'];

        return [
            ...$target->toDiffArgs(),
            ...$colorOptions,
            ...self::STABLE_DIFF_OPTIONS,
            ...$options,
        ];
    }
}
