<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AnchorStatus;
use App\Enums\DiffSide;
use App\Enums\GitRef;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One comment thread: the root comment plus its replies.
 *
 * This is the complete shape: every field any producer needs to hand a
 * thread to another producer without re-deriving or inventing anything.
 * The anchor resolvers, the drawer loader, the exporters, and
 * {@see CommentThreadSnapshot} all speak it, so a thread that survives a
 * delete/restore round trip comes back with the same side, anchor status,
 * and timestamps it went in with.
 *
 * Arrays stay at the persistence and Livewire boundaries: `fromArray()`
 * accepts either camelCase view state or a snake_case database row, and
 * `toArray()` emits the camelCase view shape.
 */
class Comment
{
    /**
     * @param  ?DiffSide  $originalSide  The stored side, when the anchor resolver moved
     *                                   the comment to the other side of the diff. Null
     *                                   means it never moved. {@see self::originalSide()}
     * @param  list<CommentReply>  $replies
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
        public readonly AnchorStatus $anchorStatus = AnchorStatus::Placed,
        public readonly ?DiffSide $originalSide = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $updatedAt = null,
        public readonly array $replies = [],
    ) {}

    /**
     * The side the comment was stored on, which is the side it is on unless an
     * anchor resolver re-anchored it across the diff.
     */
    public function originalSide(): DiffSide
    {
        return $this->originalSide ?? $this->side;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'fileId' => $this->fileId,
            'file' => $this->file,
            'side' => $this->side->value,
            'originalSide' => $this->originalSide()->value,
            'startLine' => $this->startLine,
            'endLine' => $this->endLine,
            'body' => $this->body,
            'originRef' => $this->originRef,
            'fileContentHash' => $this->fileContentHash,
            'lineSnippet' => $this->lineSnippet,
            'isDraft' => $this->isDraft,
            'submittedAt' => $this->submittedAt,
            'anchorStatus' => $this->anchorStatus->value,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
            'replies' => collect($this->replies)
                ->map(fn (CommentReply $reply): array => $reply->toArray())
                ->values()
                ->all(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rawReplies = $data['replies'] ?? [];

        return new self(
            id: self::requiredString($data, 'id'),
            fileId: (string) ($data['fileId'] ?? ''),
            file: self::requiredString($data, 'file', 'file_path'),
            side: DiffSide::from(self::requiredString($data, 'side')),
            startLine: self::intOrNull($data['startLine'] ?? $data['start_line'] ?? null),
            endLine: self::intOrNull($data['endLine'] ?? $data['end_line'] ?? null),
            body: self::requiredString($data, 'body'),
            originRef: (string) ($data['originRef'] ?? $data['origin_ref'] ?? GitRef::Working->value),
            fileContentHash: self::stringOrNull($data['fileContentHash'] ?? $data['file_content_hash'] ?? null),
            lineSnippet: self::stringOrNull($data['lineSnippet'] ?? $data['line_snippet'] ?? null),
            isDraft: (bool) ($data['isDraft'] ?? $data['is_draft'] ?? false),
            submittedAt: self::dateOrNull($data['submittedAt'] ?? $data['submitted_at'] ?? null),
            anchorStatus: AnchorStatus::tryFrom((string) ($data['anchorStatus'] ?? '')) ?? AnchorStatus::Placed,
            originalSide: DiffSide::tryFrom((string) ($data['originalSide'] ?? $data['original_side'] ?? '')),
            createdAt: self::dateOrNull($data['createdAt'] ?? $data['created_at'] ?? null),
            updatedAt: self::dateOrNull($data['updatedAt'] ?? $data['updated_at'] ?? null),
            replies: is_iterable($rawReplies) ? CommentReply::collect($rawReplies) : [],
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

    /**
     * Normalize a timestamp to ISO 8601 so a thread compares equal whether it
     * arrived from a database row, a view-state array, or a stored snapshot.
     */
    private static function dateOrNull(mixed $value): ?string
    {
        return $value === null || $value === ''
            ? null
            : Carbon::parse($value)->toIso8601String();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function requiredString(array $data, string $camelKey, ?string $snakeKey = null): string
    {
        $value = match (true) {
            array_key_exists($camelKey, $data) => $data[$camelKey],
            $snakeKey !== null && array_key_exists($snakeKey, $data) => $data[$snakeKey],
            default => throw new InvalidArgumentException("The {$camelKey} field is required."),
        };

        if (! is_string($value)) {
            throw new InvalidArgumentException("The {$camelKey} field must be a string.");
        }

        return $value;
    }
}
