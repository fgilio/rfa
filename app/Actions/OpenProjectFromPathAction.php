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
            return null;
        }

        try {
            return $this->register->handle($realPath);
        } catch (NotAGitRepositoryException) {
            // Expected: the deep-linked path simply isn't a git repo. No-op.
            return null;
        } catch (Throwable $e) {
            // Unexpected (e.g. a database error). Log it as a real failure rather
            // than silently treating it as "not a git repository", and still avoid
            // crashing the deep-link/menu handler. The Context fields surface the
            // failure on the calling owner's canonical event so a swallowed error
            // doesn't masquerade as a plain rejection there.
            Context::add('rfa.reason', 'project_registration_failed');
            Context::add('rfa.error_class', $e::class);

            Log::warning('project.registration.failed', [
                'reason' => 'project_registration_failed',
                'path' => $path,
                'error_class' => $e::class,
            ]);

            return null;
        }
    }
}
