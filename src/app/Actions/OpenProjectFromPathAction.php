<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;

final readonly class OpenProjectFromPathAction
{
    public function __construct(
        private RegisterProjectAction $register,
    ) {}

    public function handle(string $path): ?Project
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_dir($realPath)) {
            return null;
        }

        try {
            return $this->register->handle($realPath);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
