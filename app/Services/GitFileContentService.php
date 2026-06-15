<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\FileSourceSpec;
use App\Enums\GitRef;
use App\Support\PathGuard;

class GitFileContentService
{
    /**
     * Request-scoped memoization. The singleton binding in AppServiceProvider
     * is safe under shared-nothing PHP (built-in web server): each HTTP request
     * rebuilds the container, so working-copy hashes cannot go stale mid-request.
     * Under a long-lived worker (Octane/FrankenPHP/RoadRunner) callers must
     * invoke flushCache() between requests, or the singleton must be dropped.
     *
     * @var array<string, ?string>
     */
    private array $hashCache = [];

    /**
     * Request-scoped content memoization, sharing the lifecycle contract of
     * {@see self::$hashCache}. Anchor recovery reads the same content the hash
     * was computed from, so caching it avoids a second `git show` per file.
     *
     * @var array<string, ?string>
     */
    private array $contentCache = [];

    public function __construct(
        private readonly GitProcessService $gitProcessService,
    ) {}

    /**
     * Hash the contents of a file at a given ref.
     *
     * `$ref` accepts a commit SHA, branch name, or `GitRef::Working->value`,
     * which reads the current working copy from disk. Returns null when the file does
     * not exist at that ref (e.g. added-only or deleted-only changes).
     */
    public function hashAt(string $repoPath, string $ref, string $path): ?string
    {
        $key = $repoPath."\0".$ref."\0".$path;

        if (! array_key_exists($key, $this->hashCache)) {
            $content = $this->contentAt($repoPath, $ref, $path);
            $this->hashCache[$key] = $content === null ? null : hash('xxh128', $content);
        }

        return $this->hashCache[$key];
    }

    /**
     * Hash a file by its absolute on-disk path, sharing the same request-scoped
     * memoization as `hashAt()`. Used for files that live outside any repo
     * (see `GitRef::External` and `Project::external_paths`).
     */
    public function hashAtAbsolute(string $absolutePath): ?string
    {
        $key = "\0".GitRef::External->value."\0".$absolutePath;

        if (! array_key_exists($key, $this->hashCache)) {
            $content = $this->contentAtAbsolute($absolutePath);
            $this->hashCache[$key] = $content === null ? null : hash('xxh128', $content);
        }

        return $this->hashCache[$key];
    }

    /** Drop every memoized hash and content. Call between requests under long-lived workers. */
    public function flushCache(): void
    {
        $this->hashCache = [];
        $this->contentCache = [];
    }

    /**
     * Hash the file a source spec points at, dispatching on its type.
     *
     * Git sources hash the blob at their ref, absolute sources hash the
     * on-disk file, and none-sources (an absent side of a diff) hash to
     * null. Mirrors {@see self::contentForSource()}.
     */
    public function hashForSource(string $repoPath, FileSourceSpec $source): ?string
    {
        return match ($source->type) {
            FileSourceSpec::TYPE_GIT => $source->ref === null || $source->path === null
                ? null
                : $this->hashAt($repoPath, $source->ref, $source->path),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->hashAtAbsolute($source->absolutePath),
            default => null,
        };
    }

    /**
     * Read the file a source spec points at, dispatching on its type.
     *
     * The uncapped sibling of {@see FileSourceService::fetch()}: callers
     * that need size limits should go through that service instead.
     */
    public function contentForSource(string $repoPath, FileSourceSpec $source): ?string
    {
        return match ($source->type) {
            FileSourceSpec::TYPE_GIT => $source->ref === null || $source->path === null
                ? null
                : $this->contentAt($repoPath, $source->ref, $source->path),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->contentAtAbsolute($source->absolutePath),
            default => null,
        };
    }

    /**
     * Byte size of the file a source spec points at, without reading it,
     * dispatching on its type. None-sources report null.
     */
    public function byteSizeForSource(string $repoPath, FileSourceSpec $source): ?int
    {
        return match ($source->type) {
            FileSourceSpec::TYPE_GIT => $source->ref === null || $source->path === null
                ? null
                : $this->byteSizeAt($repoPath, $source->ref, $source->path),
            FileSourceSpec::TYPE_ABSOLUTE => $source->absolutePath === null
                ? null
                : $this->byteSizeAtAbsolute($source->absolutePath),
            default => null,
        };
    }

    public function contentAt(string $repoPath, string $ref, string $path): ?string
    {
        $key = $repoPath."\0".$ref."\0".$path;

        if (array_key_exists($key, $this->contentCache)) {
            return $this->contentCache[$key];
        }

        if ($this->looksLikeFlag($ref) || ! PathGuard::isRelative($path)) {
            return $this->contentCache[$key] = null;
        }

        if ($ref === GitRef::Working->value) {
            $identityPath = PathGuard::tryResolveWithinRepo($repoPath, $path, followLeaf: false);
            if ($identityPath !== null && is_link($identityPath)) {
                $target = readlink($identityPath);

                return $this->contentCache[$key] = $target === false ? null : $target;
            }

            $absolute = PathGuard::tryResolveWithinRepo($repoPath, $path);
            $content = $absolute !== null && is_file($absolute) ? @file_get_contents($absolute) : false;

            return $this->contentCache[$key] = $content === false ? null : $content;
        }

        return $this->contentCache[$key] = rescue(
            fn (): string => $this->gitProcessService->run($repoPath, ['show', $ref.':'.$path]),
            rescue: null,
            report: false,
        );
    }

    /**
     * Byte size of a file at a given ref without materializing its content,
     * so callers can enforce size caps before reading. Mirrors the source
     * resolution of {@see self::contentAt()}: working symlink leaves report
     * the target string's length, git refs ask `git cat-file -s` for the
     * blob size. Returns null when the file does not exist at that ref.
     */
    public function byteSizeAt(string $repoPath, string $ref, string $path): ?int
    {
        if ($this->looksLikeFlag($ref) || ! PathGuard::isRelative($path)) {
            return null;
        }

        if ($ref === GitRef::Working->value) {
            $identityPath = PathGuard::tryResolveWithinRepo($repoPath, $path, followLeaf: false);
            if ($identityPath !== null && is_link($identityPath)) {
                $target = readlink($identityPath);

                return $target === false ? null : strlen($target);
            }

            $absolute = PathGuard::tryResolveWithinRepo($repoPath, $path);

            return $absolute !== null && is_file($absolute) ? $this->fileSize($absolute) : null;
        }

        return rescue(
            fn (): int => (int) trim($this->gitProcessService->run($repoPath, ['cat-file', '-s', $ref.':'.$path])),
            rescue: null,
            report: false,
        );
    }

    /**
     * Byte size of a file by its absolute on-disk path without reading it.
     * Returns null when the file does not exist.
     */
    public function byteSizeAtAbsolute(string $absolutePath): ?int
    {
        return is_file($absolutePath) ? $this->fileSize($absolutePath) : null;
    }

    private function fileSize(string $absolutePath): ?int
    {
        $size = @filesize($absolutePath);

        return $size === false ? null : $size;
    }

    /**
     * Reject refs that git would parse as an option rather than a revision.
     *
     * A ref reaching this read path from a deep-link param is not guaranteed
     * to be a resolved SHA, and `git show`/`git cat-file` compose it as
     * `$ref:$path`. A leading dash (e.g. `--output=...`) would otherwise turn
     * the whole token into a flag. Mirrors {@see GitMetadataService}.
     */
    private function looksLikeFlag(string $ref): bool
    {
        return str_starts_with($ref, '-');
    }

    /**
     * Read a file by its absolute on-disk path (for files outside any repo,
     * see {@see GitRef::External} and `Project::external_paths`). Shares the
     * same request-scoped memoization as {@see self::contentAt()}.
     */
    public function contentAtAbsolute(string $absolutePath): ?string
    {
        $key = "\0".GitRef::External->value."\0".$absolutePath;

        if (array_key_exists($key, $this->contentCache)) {
            return $this->contentCache[$key];
        }

        $content = is_file($absolutePath) ? @file_get_contents($absolutePath) : false;

        return $this->contentCache[$key] = $content === false ? null : $content;
    }
}
