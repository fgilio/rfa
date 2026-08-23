<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class OpenProjectFromPathAction
{
    public function __construct(
        private RegisterProjectAction $register,
    ) {}

    public function handle(string $path): ?Project
    {
        $realPath = realpath($path);

        if ($realPath === false || ! File::isDirectory($realPath)) {
            Context::add('rfa.reason', 'path_not_found');

            return null;
        }

        try {
            return $this->register->handle($realPath);
        } catch (NotAGitRepositoryException) {
            // Expected: the deep-linked path simply isn't a git repo. The
            // Context field carries the rejection reason to the calling
            // owner's canonical event.
            Context::add('rfa.reason', 'not_a_git_repository');

            return null;
        } catch (Throwable $e) {
            // Unexpected (e.g. a database error). Log it as a real failure rather
            // than silently treating it as "not a git repository", and still avoid
            // crashing the deep-link/menu handler. The Context fields surface the
            // failure on the calling owner's canonical event so a swallowed error
            // doesn't masquerade as a plain rejection there.
            Context::add('rfa.reason', 'project_registration_failed');
            Context::add('rfa.error_class', $e::class);

            // The deep-link owner already carries this hash as rfa.path_hash,
            // so triage keeps the correlation without a second copy of
            // an absolute path in the log.
            Log::warning('project.registration.failed', [
                'reason' => 'project_registration_failed',
                'path_hash' => hash('xxh128', $path),
                'error_class' => $e::class,
            ]);

            return null;
        }
    }
}
