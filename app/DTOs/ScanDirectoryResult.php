<?php

declare(strict_types=1);

namespace App\DTOs;

class ScanDirectoryResult
{
    /**
     * @param  array<int, array{name: string, path: string, slug: string, branch: string}>  $newProjects
     * @param  array<string, string>  $errors
     */
    public function __construct(
        public readonly int $found,
        public readonly int $registered,
        public readonly int $alreadyTracked,
        public readonly int $failed,
        public readonly array $newProjects = [],
        public readonly array $errors = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'found' => $this->found,
            'registered' => $this->registered,
            'alreadyTracked' => $this->alreadyTracked,
            'failed' => $this->failed,
            'newProjects' => $this->newProjects,
            'errors' => $this->errors,
        ];
    }
}
