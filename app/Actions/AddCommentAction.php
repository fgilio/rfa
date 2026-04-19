<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Enums\DiffSide;
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

        if ($side === 'file' && ($startLine !== null || $endLine !== null)) {
            return null;
        }

        if ($side !== 'file' && $startLine === null) {
            return null;
        }

        if ($startLine !== null && $endLine !== null && $startLine > $endLine) {
            return null;
        }

        $filePath = (string) $file['path'];
        $contentHash = $this->resolveContentHash($repoPath, $target, $side, $filePath);
        $originRef = $target->to() ?? GitFileContentService::WORKING_REF;

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
            'anchorStatus' => 'placed',
        ];
    }

    private function resolveContentHash(string $repoPath, DiffTarget $target, string $side, string $filePath): ?string
    {
        if ($side === 'file') {
            $ref = $target->to() ?? GitFileContentService::WORKING_REF;

            return $this->gitFileContentService->hashAt($repoPath, $ref, $filePath);
        }

        if ($side === 'left') {
            return $this->gitFileContentService->hashAt($repoPath, $target->from(), $filePath);
        }

        $ref = $target->to() ?? GitFileContentService::WORKING_REF;

        return $this->gitFileContentService->hashAt($repoPath, $ref, $filePath);
    }
}
