<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\BranchEntry;
use App\Services\GitMetadataService;

final readonly class GetBranchListAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    /**
     * @return array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string}
     */
    public function handle(string $repoPath): array
    {
        $branches = $this->gitMetadataService->getBranches($repoPath);
        $localBranches = collect($branches['local']);
        $currentBranch = $localBranches->first(fn (BranchEntry $branch): bool => $branch->isCurrent);

        $current = $currentBranch instanceof BranchEntry ? $currentBranch->name : '';
        $local = $localBranches
            ->map(fn (BranchEntry $branch): array => $branch->toArray())
            ->all();
        $remote = collect($branches['remote'])
            ->map(fn (BranchEntry $branch): array => $branch->toArray())
            ->all();

        return [
            'local' => $local,
            'remote' => $remote,
            'current' => $current,
        ];
    }
}
