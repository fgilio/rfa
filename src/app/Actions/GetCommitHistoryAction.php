<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitDiffService;

final readonly class GetCommitHistoryAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function handle(string $repoPath, int $limit = 50, int $offset = 0, ?string $branch = null): array
    {
        $commits = $this->gitDiffService->getCommitLog($repoPath, $limit, $offset, $branch);

        return array_map(fn ($c) => $c->toArray(), $commits);
    }
}
