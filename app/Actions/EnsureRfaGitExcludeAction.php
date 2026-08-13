<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitProcessService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final readonly class EnsureRfaGitExcludeAction
{
    private const ENTRY = '.rfa/';

    public function __construct(
        private GitProcessService $git,
    ) {}

    /**
     * Ensure .rfa/ is listed in the repo's .git/info/exclude file.
     * Idempotent, non-blocking (logs on failure).
     */
    public function handle(string $repoPath): void
    {
        try {
            $excludePath = $this->resolveExcludePath($repoPath);

            if ($excludePath === null) {
                return;
            }

            $dir = dirname($excludePath);

            if (! File::isDirectory($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            $contents = File::exists($excludePath) ? File::get($excludePath) : '';

            if ($this->alreadyExcluded($contents)) {
                return;
            }

            $line = self::ENTRY;

            if ($contents !== '' && ! str_ends_with($contents, "\n")) {
                $line = "\n".$line;
            }

            File::append($excludePath, $line."\n");
        } catch (\Throwable $e) {
            Log::warning('git.exclude.append_failed', [
                'reason' => 'exclude_append_failed',
                'error_class' => $e::class,
            ]);
        }
    }

    private function resolveExcludePath(string $repoPath): ?string
    {
        try {
            $path = trim($this->git->run($repoPath, ['rev-parse', '--git-path', 'info/exclude']));

            if ($path === '') {
                return null;
            }

            // git may return a relative path
            if (! str_starts_with($path, '/')) {
                $path = $repoPath.'/'.$path;
            }

            return $path;
        } catch (\Throwable $e) {
            Log::warning('git.exclude.resolve_failed', [
                'reason' => 'exclude_path_resolve_failed',
                'error_class' => $e::class,
            ]);

            return null;
        }
    }

    private function alreadyExcluded(string $contents): bool
    {
        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim($line);

            if ($trimmed === self::ENTRY || $trimmed === '.rfa') {
                return true;
            }
        }

        return false;
    }
}
