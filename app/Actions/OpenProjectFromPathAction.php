<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

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
        } catch (\RuntimeException $e) {
            // The path itself is the failed input being diagnosed, which the
            // wide-events standard permits in warning payloads.
            Log::warning('project.registration.failed', [
                'reason' => 'project_registration_failed',
                'path' => $path,
                'error_class' => $e::class,
                'error_summary' => LogSanitizer::summarize($e->getMessage()),
            ]);

            return null;
        }
    }
}
