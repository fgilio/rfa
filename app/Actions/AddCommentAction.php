<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use App\Models\Comment;
use App\Services\GitFileContentService;
use Illuminate\Support\Str;

final readonly class AddCommentAction
{
    public function __construct(
        private GitFileContentService $gitFileContentService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    public function handle(
        string $repoPath,
        ?int $projectId,
        DiffTarget $target,
        array $files,
        string $fileId,
        string $side,
        ?int $startLine,
        ?int $endLine,
        string $body,
        bool $isDraft = false,
        ?string $lineSnippet = null,
    ): ?array {
        if (trim($body) === '') {
            return null;
        }

        $file = collect($files)->keyBy('id')->get($fileId);
        if (! $file || DiffSide::tryFrom($side) === null) {
            return null;
        }

        if ($side === DiffSide::File->value && ($startLine !== null || $endLine !== null)) {
            return null;
        }

        if ($side !== DiffSide::File->value && $startLine === null) {
            return null;
        }

        if ($startLine !== null && $endLine !== null && $startLine > $endLine) {
            return null;
        }

        $filePath = (string) $file['path'];
        $oldPath = ! empty($file['oldPath']) ? (string) $file['oldPath'] : null;
        $isExternal = (bool) ($file['isExternal'] ?? false);
        $source = $isExternal
            ? FileSourceSpec::absolute((string) ($file['externalAbsolutePath'] ?? ''))
            : FileSourceSpec::forSide($target, DiffSide::from($side), $filePath, $oldPath);
        $contentHash = $this->gitFileContentService->hashForSource($repoPath, $source);
        $originRef = $isExternal
            ? GitRef::External->value
            : ($target->to() ?? GitRef::Working->value);

        $id = 'c-'.Str::ulid();

        Comment::create([
            'id' => $id,
            'project_id' => $projectId,
            'repo_path' => $repoPath,
            'origin_ref' => $originRef,
            'file_path' => $filePath,
            'side' => $side,
            'start_line' => $startLine,
            'end_line' => $endLine,
            'file_content_hash' => $contentHash,
            'line_snippet' => $lineSnippet,
            'body' => $body,
            'is_draft' => $isDraft,
        ]);

        return [
            'id' => $id,
            'fileId' => $fileId,
            'file' => $filePath,
            'side' => $side,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'body' => $body,
            'originRef' => $originRef,
            'fileContentHash' => $contentHash,
            'lineSnippet' => $lineSnippet,
            'isDraft' => $isDraft,
            'submittedAt' => null,
            'anchorStatus' => AnchorStatus::Placed->value,
        ];
    }
}
