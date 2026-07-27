<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CommentThreadDeletion
{
    /**
     * @param  list<array<string, mixed>>  $remainingComments
     * @param  list<array<string, mixed>>  $snapshots
     */
    public function __construct(
        public array $remainingComments,
        public array $snapshots,
    ) {}

    /**
     * @return array{
     *     remainingComments: list<array<string, mixed>>,
     *     snapshots: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'remainingComments' => $this->remainingComments,
            'snapshots' => $this->snapshots,
        ];
    }
}
