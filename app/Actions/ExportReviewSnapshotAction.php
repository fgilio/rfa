<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\ReviewSnapshot;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ExportReviewSnapshotAction
{
    public function __construct(
        private DeriveReviewStateAction $deriveReviewState,
        private EnsureRfaGitExcludeAction $ensureGitExclude,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<string, mixed>  $reviewedFiles
     * @return array{json: string, clipboard: string, snapshot: array<string, mixed>}
     */
    public function handle(
        string $repoPath,
        array $files,
        array $comments = [],
        string $globalComment = '',
        array $reviewedFiles = [],
        ?DiffTarget $target = null,
        ?string $sourceLabel = null,
    ): array {
        $target ??= DiffTarget::workingDirectory();
        $sourceLabel ??= basename($repoPath);

        $reviewState = $this->deriveReviewState->handle(
            files: $files,
            reviewedFiles: $reviewedFiles,
        );

        $snapshot = new ReviewSnapshot(
            repoPath: $repoPath,
            sourceLabel: $sourceLabel,
            target: $target,
            files: $reviewState->sourceFiles,
            comments: array_values($comments),
            reviewedFileIds: $reviewState->reviewedFileIds,
            reviewedFiles: $reviewedFiles,
            globalComment: $globalComment,
            exportedAt: now()->toIso8601String(),
        );

        $path = $this->writeSnapshot($repoPath, $snapshot);

        $this->ensureGitExclude->handle($repoPath);

        return [
            'json' => $path,
            'clipboard' => "use the review snapshot in {$path}",
            'snapshot' => $snapshot->toArray(),
        ];
    }

    private function writeSnapshot(string $repoPath, ReviewSnapshot $snapshot): string
    {
        $basename = date('Ymd_His').'_snapshot_'.Str::random(8);
        $rfaDir = $repoPath.'/.rfa';
        $path = "{$rfaDir}/{$basename}.json";

        File::ensureDirectoryExists($rfaDir);

        $json = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        if (File::put($path, $json) === false) {
            throw new RuntimeException("Failed to write review snapshot: {$path}");
        }

        return $path;
    }
}
