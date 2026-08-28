<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\NotAGitRepositoryException;
use App\Models\Project;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\File;
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
            // The caller owns the canonical event, so preserve the failure in
            // Context while keeping this action non-throwing.
            Context::add('rfa.reason', 'project_registration_failed');
            Context::add('rfa.error_class', $e::class);

            return null;
        }
    }
}
