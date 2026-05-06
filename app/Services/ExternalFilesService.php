<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FileListEntry;
use Carbon\Carbon;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Number;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ExternalFilesService
{
    public const MOUNT_PREFIX = 'external';

    /**
     * Cap recursion depth so a misconfigured root (e.g. `$HOME`) can't pull a
     * massive subtree into the file list. Eight levels comfortably covers
     * design-note folders without inviting pathological walks.
     */
    public const MAX_DEPTH = 8;

    public function __construct(
        private readonly GitDiffService $gitDiffService,
    ) {}

    /**
     * Build synthetic FileListEntry rows for every configured external directory.
     * Each file is mounted under `external/<label>/<relative>` so the synthetic
     * path stays stable across sessions and yields the same file id.
     *
     * @param  array<int, mixed>  $rawConfigs  Raw rows from `Project::external_paths`.
     * @return list<FileListEntry>
     */
    public function getEntries(array $rawConfigs): array
    {
        $configs = $this->resolvedConfigs($rawConfigs);

        if ($configs === []) {
            return [];
        }

        return collect($configs)
            ->flatMap(fn (array $config): array => $this->entriesForConfig($config))
            ->values()
            ->all();
    }

    /**
     * Resolve the absolute on-disk path for a given mount path under the supplied
     * configs, or null if the mount doesn't match a configured external directory.
     *
     * @param  array<int, mixed>  $rawConfigs  Raw rows from `Project::external_paths`.
     */
    public function resolveAbsolutePath(array $rawConfigs, string $mountPath): ?string
    {
        if (! str_starts_with($mountPath, self::MOUNT_PREFIX.'/')) {
            return null;
        }

        $configs = $this->resolvedConfigs($rawConfigs);
        $remainder = substr($mountPath, strlen(self::MOUNT_PREFIX) + 1);

        foreach ($configs as $config) {
            $prefix = $config['label'].'/';

            if (! str_starts_with($remainder, $prefix)) {
                continue;
            }

            $candidate = $config['root'].DIRECTORY_SEPARATOR.substr($remainder, strlen($prefix));

            if (! File::isFile($candidate)) {
                return null;
            }

            $real = realpath($candidate);
            if ($real === false || ! str_starts_with($real, $config['root'].DIRECTORY_SEPARATOR)) {
                return null;
            }

            return $real;
        }

        return null;
    }

    /**
     * Build a synthetic unified diff for an external file: whole-file "added"
     * view against /dev/null, identical to what `git diff` produces for a
     * brand-new file. Returns null if the file exceeds $maxBytes, '' for
     * empty files, and a header-only diff for binaries.
     */
    public function buildDiff(string $absolutePath, string $mountPath, ?int $maxBytes = null): ?string
    {
        $maxBytes ??= (int) config('rfa.diff_max_bytes', 512_000);

        return $this->gitDiffService->buildAddedFileDiff($absolutePath, $mountPath, $maxBytes);
    }

    /**
     * Normalize raw `external_paths` rows into the canonical storage shape used
     * by Project::external_paths: `{label, path}` with a non-empty label that
     * defaults to `basename($path)`. Tolerates malformed rows by dropping them.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array{label: string, path: string}>
     */
    public function normalizeForStorage(array $raw): array
    {
        return collect($raw)
            ->filter(fn ($row): bool => is_array($row) && isset($row['path']) && is_string($row['path']))
            ->map(fn (array $row): array => [
                'label' => isset($row['label']) && is_string($row['label']) && trim($row['label']) !== ''
                    ? $row['label']
                    : basename((string) $row['path']),
                'path' => (string) $row['path'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{label: string, root: string}  $config
     * @return list<FileListEntry>
     */
    private function entriesForConfig(array $config): array
    {
        $root = $config['root'];

        if (! is_dir($root)) {
            return [];
        }

        // Don't follow symlinks: a link inside the configured root could point
        // anywhere on disk (e.g. `~`, `/etc`), which would walk out of scope
        // and surface unrelated files. resolveAbsolutePath() also re-checks
        // confinement on the read side; this is the matching write-side guard.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        $iterator->setMaxDepth(self::MAX_DEPTH);

        $entries = [];
        $rootPrefix = $root.DIRECTORY_SEPARATOR;

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            // Defense in depth: drop entries whose canonical path leaves the
            // configured root, even if a symlink slipped past the flag above.
            $absolutePath = realpath($file->getPathname());
            if ($absolutePath === false || ! str_starts_with($absolutePath, $rootPrefix)) {
                continue;
            }

            $additions = $this->streamCountLines($absolutePath);

            // streamCountLines returns null for binary files in a single read
            // pass; skip them rather than listing meaningless line counts.
            if ($additions === null) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR,
                '/',
                ltrim(substr($absolutePath, strlen($root)), DIRECTORY_SEPARATOR),
            );

            $size = $file->getSize();
            $mtime = $file->getMTime();

            $entries[] = new FileListEntry(
                path: self::MOUNT_PREFIX.'/'.$config['label'].'/'.$relative,
                status: 'added',
                oldPath: null,
                additions: $additions,
                deletions: 0,
                isBinary: false,
                isUntracked: false,
                lastModified: Carbon::createFromTimestamp($mtime)->diffForHumans(short: true),
                isSymlink: false,
                symlinkTarget: null,
                fileSize: Number::fileSize($size, precision: 1),
                isExternal: true,
                externalAbsolutePath: $absolutePath,
                mtime: $mtime,
                byteSize: $size,
            );
        }

        usort($entries, fn (FileListEntry $a, FileListEntry $b): int => strcmp($a->path, $b->path));

        return $entries;
    }

    /**
     * Resolve normalized rows to absolute roots ready for filesystem walking.
     * Drops rows whose path no longer exists. Stored labels are used as-is
     * (only sanitized) — disambiguation happens at link time so unlinking a
     * sibling never renames a surviving mount.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array{label: string, root: string}>
     */
    private function resolvedConfigs(array $raw): array
    {
        return collect($this->normalizeForStorage($raw))
            ->map(function (array $row): ?array {
                $root = realpath($row['path']);
                if ($root === false || ! is_dir($root)) {
                    return null;
                }

                return ['label' => $this->sanitizeLabel($row['label']), 'root' => $root];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Pick a sanitized, unique label for a new external path against the labels
     * already used by other rows. Called at link time so the disambiguated
     * label is persisted and stable across future unlinks.
     *
     * @param  list<array{label: string, path: string}>  $existing
     */
    public function uniqueLabelFor(array $existing, string $candidate): string
    {
        $base = $this->sanitizeLabel($candidate);
        $taken = array_flip(array_map(fn (array $row): string => $this->sanitizeLabel($row['label']), $existing));

        if (! isset($taken[$base])) {
            return $base;
        }

        $suffix = 2;
        while (isset($taken[$base.'-'.$suffix])) {
            $suffix++;
        }

        return $base.'-'.$suffix;
    }

    private function sanitizeLabel(string $label): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $label), '-');

        return $clean === '' ? 'external' : $clean;
    }

    /**
     * Stream-count newlines while opportunistically detecting binary files
     * (NUL byte in the first chunk). Returns null for binaries so the caller
     * can skip them, 0 for empty/unreadable, and the line count otherwise.
     * One disk pass per file — replaces the prior open+read+close-twice.
     */
    private function streamCountLines(string $absolutePath): ?int
    {
        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        $lastByte = '';
        $firstChunk = true;
        while (! feof($handle)) {
            $chunk = (string) fread($handle, 65_536);
            if ($chunk === '') {
                break;
            }
            if ($firstChunk && str_contains($chunk, "\0")) {
                fclose($handle);

                return null;
            }
            $firstChunk = false;
            $count += substr_count($chunk, "\n");
            $lastByte = $chunk[strlen($chunk) - 1];
        }
        fclose($handle);

        return $lastByte !== '' && $lastByte !== "\n" ? $count + 1 : $count;
    }
}
