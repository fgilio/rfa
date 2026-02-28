<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\GitDiffService;

final readonly class GetBranchListAction
{
    public function __construct(
        private GitDiffService $gitDiffService,
    ) {}

    /**
     * @return array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string}
     */
    public function handle(string $repoPath): array
    {
        $branches = $this->gitDiffService->getBranches($repoPath);

        $current = '';
        $local = [];

        foreach ($branches['local'] as $branch) {
            if ($branch->isCurrent) {
                $current = $branch->name;
            }
            $local[] = $branch->toArray();
        }

        $remote = array_map(fn ($b) => $b->toArray(), $branches['remote']);

        return [
            'local' => $local,
            'remote' => array_values($remote),
            'current' => $current,
        ];
    }
}
