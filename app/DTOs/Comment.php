<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Enums\GitRef;

class Comment
{
    public function __construct(
        public readonly string $id,
        public readonly string $fileId,
        public readonly string $file,
        public readonly DiffSide $side,
        public readonly ?int $startLine,
        public readonly ?int $endLine,
        public readonly string $body,
        public readonly string $originRef = GitRef::Working->value,
        public readonly ?string $fileContentHash = null,
        public readonly ?string $lineSnippet = null,
        public readonly bool $isDraft = false,
        public readonly ?string $submittedAt = null,
        public readonly string $anchorStatus = AnchorStatus::Placed->value,
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
            originRef: $data['originRef'] ?? GitRef::Working->value,
            fileContentHash: $data['fileContentHash'] ?? null,
            lineSnippet: $data['lineSnippet'] ?? null,
            isDraft: (bool) ($data['isDraft'] ?? false),
            submittedAt: $data['submittedAt'] ?? null,
            anchorStatus: $data['anchorStatus'] ?? AnchorStatus::Placed->value,
        );
    }
}
