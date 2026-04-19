<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ProjectListResult
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $groups
     */
    public function __construct(
        public array $groups,
        public int $total,
        public int $matchCount,
    ) {}

    /** @return array{groups: array<string, list<array<string, mixed>>>, total: int, matchCount: int} */
    public function toArray(): array
    {
        return [
            'groups' => $this->groups,
            'total' => $this->total,
            'matchCount' => $this->matchCount,
        ];
    }
}
