<?php

declare(strict_types=1);

namespace App\Services;

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

    public function contentAt(string $repoPath, string $ref, string $path): ?string
    {
        $key = $repoPath."\0".$ref."\0".$path;

        if (array_key_exists($key, $this->contentCache)) {
            return $this->contentCache[$key];
        }

        if (! $this->isValidRepoPath($path)) {
            return $this->contentCache[$key] = null;
        }

        if ($ref === GitRef::Working->value) {
            $absolute = $this->readableRepoPath($repoPath, $path);
            $content = $absolute !== null && is_file($absolute) ? @file_get_contents($absolute) : false;

            return $this->contentCache[$key] = $content === false ? null : $content;
        }

        if ($ref === GitRef::Index->value) {
            return $this->contentCache[$key] = rescue(
                fn (): string => $this->gitProcessService->run($repoPath, ['show', ':'.$path]),
                rescue: null,
                report: false,
            );
        }

        return $this->contentCache[$key] = rescue(
            fn (): string => $this->gitProcessService->run($repoPath, ['show', $ref.':'.$path]),
            rescue: null,
            report: false,
        );
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

    private function isValidRepoPath(string $path): bool
    {
        return rescue(function () use ($path): bool {
            PathGuard::assertRelative($path);

            return true;
        }, rescue: false, report: false);
    }

    private function readableRepoPath(string $repoPath, string $path): ?string
    {
        return rescue(
            fn (): ?string => PathGuard::resolveWithinRepo($repoPath, $path),
            rescue: null,
            report: false,
        );
    }
}
