<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DiffSide;

class Comment
{
    /** Mirrors GitFileContentService::WORKING_REF (kept here so the DTO stays standalone). */
    public const WORKING_REF = 'working';

    public function __construct(
        public readonly string $id,
        public readonly string $fileId,
        public readonly string $file,
        public readonly DiffSide $side,
        public readonly ?int $startLine,
        public readonly ?int $endLine,
        public readonly string $body,
        public readonly string $originRef = self::WORKING_REF,
        public readonly ?string $fileContentHash = null,
        public readonly ?string $lineSnippet = null,
        public readonly bool $isDraft = false,
        public readonly ?string $submittedAt = null,
        public readonly string $anchorStatus = 'placed',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fileId' => $this->fileId,
            'file' => $this->file,
            'side' => $this->side->value,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'body' => $this->body,
            'originRef' => $this->originRef,
            'fileContentHash' => $this->fileContentHash,
            'lineSnippet' => $this->lineSnippet,
            'isDraft' => $this->isDraft,
            'submittedAt' => $this->submittedAt,
            'anchorStatus' => $this->anchorStatus,
        ];
    }

    /** @return array<string, mixed> */
    public function toExportArray(): array
    {
        return [
            'id' => $this->id,
            'file' => $this->file,
            'side' => $this->side->value,
            'start_line' => $this->startLine,
            'end_line' => $this->endLine,
            'body' => $this->body,
            'anchor' => [
                'origin_ref' => $this->originRef,
                'file_content_hash' => $this->fileContentHash,
                'line_snippet' => $this->lineSnippet,
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            fileId: $data['fileId'] ?? '',
            file: $data['file'],
            side: DiffSide::from($data['side']),
            startLine: $data['startLine'] ?? null,
            endLine: $data['endLine'] ?? null,
            body: $data['body'],
            originRef: $data['originRef'] ?? self::WORKING_REF,
            fileContentHash: $data['fileContentHash'] ?? null,
            lineSnippet: $data['lineSnippet'] ?? null,
            isDraft: (bool) ($data['isDraft'] ?? false),
            submittedAt: $data['submittedAt'] ?? null,
            anchorStatus: $data['anchorStatus'] ?? 'placed',
        );
    }
}
