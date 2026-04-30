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
     * Build synthetic FileListEntry rows for every configured external directory.
     * Each file is mounted under `external/<label>/<relative>` so the synthetic
     * path stays stable across sessions and yields the same file id.
     *
     * @param  array<int, mixed>  $rawConfigs  Raw rows from `Project::external_paths`.
     * @return list<FileListEntry>
     */
    public function getEntries(array $rawConfigs): array
    {
        $configs = $this->normalizeConfigs($rawConfigs);

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

        $configs = $this->normalizeConfigs($rawConfigs);
        $remainder = substr($mountPath, strlen(self::MOUNT_PREFIX) + 1);

        foreach ($configs as $config) {
            $prefix = $config['label'].'/';

            if (! str_starts_with($remainder, $prefix)) {
                continue;
            }

            $relative = substr($remainder, strlen($prefix));
            $candidate = $config['root'].DIRECTORY_SEPARATOR.$relative;

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
     * view against /dev/null, mirroring the format `git diff` would produce
     * for a brand-new file. Returns null if the file exceeds $maxBytes, ''
     * for empty files, and a header-only diff for binaries.
     */
    public function buildDiff(string $absolutePath, string $mountPath, ?int $maxBytes = null): ?string
    {
        $maxBytes ??= (int) config('rfa.diff_max_bytes', 512_000);

        if (! File::isFile($absolutePath)) {
            return '';
        }

        if ($this->isBinary($absolutePath)) {
            return "diff --git a/{$mountPath} b/{$mountPath}\nnew file mode 100644\nBinary files /dev/null and b/{$mountPath} differ\n";
        }

        $size = File::size($absolutePath);
        if ($size > $maxBytes) {
            return null;
        }

        $content = File::get($absolutePath);
        if ($content === '') {
            return "diff --git a/{$mountPath} b/{$mountPath}\nnew file mode 100644\n";
        }

        $lines = explode("\n", $content);
        if (end($lines) === '') {
            array_pop($lines);
        }

        $diff = "diff --git a/{$mountPath} b/{$mountPath}\n";
        $diff .= "new file mode 100644\n";
        $diff .= "--- /dev/null\n";
        $diff .= "+++ b/{$mountPath}\n";
        $diff .= '@@ -0,0 +1,'.count($lines)." @@\n";
        $diff .= '+'.implode("\n+", $lines)."\n";

        return $diff;
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

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $entries = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $absolutePath = $file->getPathname();

            if ($this->isBinary($absolutePath)) {
                continue;
            }

            $relative = ltrim(substr($absolutePath, strlen($root)), DIRECTORY_SEPARATOR);
            // Normalize Windows separators for the synthetic mount path.
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            $mountPath = self::MOUNT_PREFIX.'/'.$config['label'].'/'.$relative;

            $size = $file->getSize();
            $content = $size > 0 ? @file_get_contents($absolutePath) : '';
            $additions = is_string($content) && $content !== ''
                ? substr_count($content, "\n") + (str_ends_with($content, "\n") ? 0 : 1)
                : 0;

            $entries[] = new FileListEntry(
                path: $mountPath,
                status: 'added',
                oldPath: null,
                additions: $additions,
                deletions: 0,
                isBinary: false,
                isUntracked: false,
                lastModified: Carbon::createFromTimestamp($file->getMTime())->diffForHumans(short: true),
                isSymlink: false,
                symlinkTarget: null,
                fileSize: Number::fileSize($size, precision: 1),
                isExternal: true,
                externalAbsolutePath: $absolutePath,
            );
        }

        usort($entries, fn (FileListEntry $a, FileListEntry $b): int => strcmp($a->path, $b->path));

        return $entries;
    }

    /**
     * Normalize raw config rows from the JSON column into a canonical shape:
     * `{label: string, root: string}` with a real, absolute root.
     *
     * @param  array<int|string, mixed>  $raw
     * @return list<array{label: string, root: string}>
     */
    private function normalizeConfigs(array $raw): array
    {
        $usedLabels = [];

        return collect($raw)
            ->map(function ($entry) use (&$usedLabels): ?array {
                if (! is_array($entry) || ! isset($entry['path']) || ! is_string($entry['path'])) {
                    return null;
                }

                $root = realpath($entry['path']);
                if ($root === false || ! is_dir($root)) {
                    return null;
                }

                $label = isset($entry['label']) && is_string($entry['label']) && trim($entry['label']) !== ''
                    ? $this->sanitizeLabel($entry['label'])
                    : $this->sanitizeLabel(basename($root));

                $base = $label;
                $suffix = 2;
                while (isset($usedLabels[$label])) {
                    $label = $base.'-'.$suffix++;
                }
                $usedLabels[$label] = true;

                return ['label' => $label, 'root' => $root];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function sanitizeLabel(string $label): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $label) ?? '';
        $clean = trim($clean, '-');

        return $clean === '' ? 'external' : $clean;
    }

    private function isBinary(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return true;
        }

        $chunk = (string) fread($handle, 8192);
        fclose($handle);

        return str_contains($chunk, "\0");
    }
}
