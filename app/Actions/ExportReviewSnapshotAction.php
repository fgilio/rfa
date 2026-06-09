<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\DTOs\ReviewSnapshot;
use App\Enums\GitRef;
use App\Services\FileSourceService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final readonly class ExportReviewSnapshotAction
{
    public function __construct(
        private DeriveReviewStateAction $deriveReviewState,
        private FileSourceService $fileSourceService,
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
            files: $this->withSourceText($repoPath, $target, $reviewState->sourceFiles),
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

        $json = json_encode($snapshot->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n";

        if (File::put($path, $json) === false) {
            throw new RuntimeException("Failed to write review snapshot: {$path}");
        }

        return $path;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @return list<array<string, mixed>>
     */
    private function withSourceText(string $repoPath, DiffTarget $target, array $files): array
    {
        return collect($files)
            ->map(function (array $file) use ($repoPath, $target): array {
                [$oldSource, $newSource] = $this->sourceSpecs($file, $target);

                return $file + [
                    'oldSource' => $oldSource->toArray(),
                    'newSource' => $newSource->toArray(),
                    'oldSourceText' => $this->fileSourceService->fetch($repoPath, $oldSource)->toArray(),
                    'newSourceText' => $this->fileSourceService->fetch($repoPath, $newSource)->toArray(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{0: FileSourceSpec, 1: FileSourceSpec}
     */
    private function sourceSpecs(array $file, DiffTarget $target): array
    {
        $path = (string) ($file['path'] ?? '');
        $status = (string) ($file['status'] ?? 'modified');

        if (($file['isExternal'] ?? false) && ! empty($file['externalAbsolutePath'])) {
            return [
                FileSourceSpec::none(),
                FileSourceSpec::absolute((string) $file['externalAbsolutePath']),
            ];
        }

        $oldSource = $status === 'added' || ($file['isUntracked'] ?? false)
            ? FileSourceSpec::none()
            : FileSourceSpec::git($target->from(), (string) ($file['oldPath'] ?? $path));

        $newSource = $status === 'deleted'
            ? FileSourceSpec::none()
            : FileSourceSpec::git($target->to() ?? GitRef::Working->value, $path);

        return [$oldSource, $newSource];
    }
}
