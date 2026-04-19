<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GitCommandException;

class GitFileContentService
{
    public const WORKING_REF = 'working';

    /** @var array<string, ?string> */
    private array $hashCache = [];

    public function __construct(
        private readonly GitProcessService $gitProcessService,
    ) {}

    /**
     * Hash the contents of a file at a given ref.
     *
     * `$ref` accepts a commit SHA, branch name, or the special value `self::WORKING_REF`,
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

    public function contentAt(string $repoPath, string $ref, string $path): ?string
    {
        if ($ref === self::WORKING_REF) {
            $absolute = rtrim($repoPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;

            if (! is_file($absolute)) {
                return null;
            }

            $content = @file_get_contents($absolute);

            return $content === false ? null : $content;
        }

        try {
            return $this->gitProcessService->run($repoPath, ['show', $ref.':'.$path]);
        } catch (GitCommandException) {
            return null;
        }
    }
}
