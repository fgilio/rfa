<?php

declare(strict_types=1);

namespace App\DTOs;

use InvalidArgumentException;

/**
 * A comment thread captured before a cascading delete, so undo can put it back
 * exactly as it was.
 *
 * The snapshot owns a {@see Comment} rather than a parallel comment shape of
 * its own. Every field restore needs (side, original side, anchor status,
 * origin ref, timestamps, replies in order) is on the thread itself, so
 * restore never has to invent a value the snapshot failed to carry.
 *
 * Stored form is the schema version 1 `{version, comment, replies}` envelope.
 * `fromArray()` also accepts a bare comment array, the shape an undo payload
 * can still arrive in.
 */
final readonly class CommentThreadSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public Comment $comment,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  ?string  $defaultOriginRef  Applied only when the payload carries no
     *                                     origin ref at all, so a snapshot taken
     *                                     on a non-default surface (e.g. the
     *                                     Context page) still restores there.
     */
    public static function fromArray(array $data, ?string $defaultOriginRef = null): self
    {
        $schemaVersion = (int) ($data['version'] ?? $data['schemaVersion'] ?? self::SCHEMA_VERSION);

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException("Unsupported comment thread snapshot version: {$schemaVersion}.");
        }

        $comment = isset($data['comment']) && is_array($data['comment'])
            ? $data['comment']
            : $data;
        $replies = $data['replies'] ?? $comment['replies'] ?? [];

        unset($comment['version'], $comment['schemaVersion'], $comment['comment']);

        $comment['replies'] = is_iterable($replies) ? $replies : [];

        if ($defaultOriginRef !== null
            && ! array_key_exists('originRef', $comment)
            && ! array_key_exists('origin_ref', $comment)) {
            $comment['originRef'] = $defaultOriginRef;
        }

        return new self(Comment::fromArray($comment));
    }

    /** @return list<CommentReply> */
    public function replies(): array
    {
        return $this->comment->replies;
    }

    /** @return array{version: int, comment: array<string, mixed>, replies: list<array<string, mixed>>} */
    public function toArray(): array
    {
        $comment = $this->comment->toArray();
        $replies = $comment['replies'];
        unset($comment['replies']);

        return [
            'version' => self::SCHEMA_VERSION,
            'comment' => $comment,
            'replies' => $replies,
        ];
    }

    /** @return array<string, mixed> */
    public function toCommentArray(): array
    {
        return $this->comment->toArray();
    }

    public function commentId(): string
    {
        return $this->comment->id;
    }

    public function fileId(): ?string
    {
        return $this->comment->fileId !== '' ? $this->comment->fileId : null;
    }
}
