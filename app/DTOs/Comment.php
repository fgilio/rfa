<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Enums\GitRef;

class Comment
{
    /**
     * @param  list<array<string, mixed>>  $replies
     */
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
        public readonly array $replies = [],
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
            'replies' => collect($this->replies)
                ->map(fn (array $reply): array => CommentReply::fromArray($reply)->toArray())
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawReplies = $data['replies'] ?? [];
        $replies = is_iterable($rawReplies)
            ? CommentReply::collect($rawReplies)
            : [];

        return new self(
            id: (string) ($data['id'] ?? ''),
            fileId: (string) ($data['fileId'] ?? ''),
            file: (string) ($data['file'] ?? $data['file_path'] ?? ''),
            side: self::diffSide((string) ($data['side'] ?? DiffSide::Right->value)),
            startLine: self::intOrNull($data['startLine'] ?? $data['start_line'] ?? null),
            endLine: self::intOrNull($data['endLine'] ?? $data['end_line'] ?? null),
            body: (string) ($data['body'] ?? ''),
            originRef: (string) ($data['originRef'] ?? $data['origin_ref'] ?? GitRef::Working->value),
            fileContentHash: self::stringOrNull($data['fileContentHash'] ?? $data['file_content_hash'] ?? null),
            lineSnippet: self::stringOrNull($data['lineSnippet'] ?? $data['line_snippet'] ?? null),
            isDraft: (bool) ($data['isDraft'] ?? $data['is_draft'] ?? false),
            submittedAt: self::stringOrNull($data['submittedAt'] ?? $data['submitted_at'] ?? null),
            anchorStatus: $data['anchorStatus'] ?? AnchorStatus::Placed->value,
            replies: collect($replies)
                ->map(fn (CommentReply $reply): array => $reply->toArray())
                ->values()
                ->all(),
        );
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private static function diffSide(string $side): DiffSide
    {
        return DiffSide::tryFrom($side) ?? match ($side) {
            'old' => DiffSide::Left,
            'new' => DiffSide::Right,
            default => DiffSide::Right,
        };
    }
}
