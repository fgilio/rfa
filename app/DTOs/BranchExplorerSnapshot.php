<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class BranchExplorerSnapshot
{
    /**
     * @param  array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string}  $branches
     * @param  array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int}  $branchBase
     * @param  list<array<string, mixed>>  $commits
     */
    public function __construct(
        public array $branches,
        public string $selectedBranch,
        public ?string $selectedBranchTipSha,
        public array $branchBase,
        public array $commits,
        public bool $hasMore,
        public int $pageSize,
        public int $loadedLimit,
        public string $snapshotKey,
    ) {}

    /**
     * @param  array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int}  $branchBase
     */
    public static function keyFor(string $selectedBranch, ?string $selectedBranchTipSha, array $branchBase): string
    {
        return hash('xxh128', json_encode([
            'selectedBranch' => $selectedBranch,
            'selectedBranchTipSha' => $selectedBranchTipSha,
            'branchBaseState' => $branchBase['state'],
            'branchBaseBranch' => $branchBase['baseBranch'],
            'branchBaseSha' => $branchBase['baseSha'],
            'branchBaseHashes' => $branchBase['hashesInRange'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{
     *     branches: array{local: list<array<string, mixed>>, remote: list<array<string, mixed>>, current: string},
     *     selectedBranch: string,
     *     selectedBranchTipSha: ?string,
     *     branchBase: array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int},
     *     commits: list<array<string, mixed>>,
     *     hasMore: bool,
     *     pageSize: int,
     *     loadedLimit: int,
     *     snapshotKey: string
     * }
     */
    public function toArray(): array
    {
        return [
            'branches' => $this->branches,
            'selectedBranch' => $this->selectedBranch,
            'selectedBranchTipSha' => $this->selectedBranchTipSha,
            'branchBase' => $this->branchBase,
            'commits' => $this->commits,
            'hasMore' => $this->hasMore,
            'pageSize' => $this->pageSize,
            'loadedLimit' => $this->loadedLimit,
            'snapshotKey' => $this->snapshotKey,
        ];
    }
}
