<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitMetadataService;

final readonly class GetCommitHistoryAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $repoPath, int $limit = 50, int $offset = 0, ?string $branch = null): array
    {
        $commits = $this->gitMetadataService->getCommitLog($repoPath, $limit, $offset, $branch);

        return array_map(fn ($c) => $c->toArray(), $commits);
    }
}
