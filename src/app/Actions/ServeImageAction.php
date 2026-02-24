<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\GitDiffService;

final readonly class ServeImageAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /** @return array{content: string, mimeType: string}|null */
    public function handle(int $projectId, string $path, string $ref): ?array
    {
        $project = Project::findOrFail($projectId);

        $content = $this->gitDiffService->getFileContent($project->path, $path, $ref);

        if ($content === null) {
            return null;
        }

        return [
            'content' => $content,
            'mimeType' => $this->mimeFromExtension($path),
        ];
    }

    private function mimeFromExtension(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            'bmp' => 'image/bmp',
            'avif' => 'image/avif',
            default => 'application/octet-stream',
        };
    }
}
