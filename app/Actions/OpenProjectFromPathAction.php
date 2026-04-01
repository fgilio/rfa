<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\File;

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
        } catch (\RuntimeException) {
            return null;
        }
    }
}
