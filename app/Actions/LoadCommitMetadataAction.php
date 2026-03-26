<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Services\GitMetadataService;

final readonly class LoadCommitMetadataAction
{
    public function __construct(
        private GitMetadataService $git,
    ) {}

    /** @return array{shortHash: string, message: string, author: string, prevHash: ?string, nextHash: ?string} */
    public function handle(string $repoPath, string $commitHash, string $parentHash): array
    {
        $commits = $this->git->getCommitLog($repoPath, limit: 1, branch: $commitHash);
        $commit = $commits[0] ?? null;

        $prevHash = $parentHash !== DiffTarget::EMPTY_TREE_HASH ? $parentHash : null;
        $nextHash = $this->git->getChildCommit($repoPath, $commitHash);

        if ($commit === null) {
            return [
                'shortHash' => substr($commitHash, 0, 7),
                'message' => '',
                'author' => '',
                'prevHash' => $prevHash,
                'nextHash' => $nextHash,
            ];
        }

        return [
            'shortHash' => $commit->shortHash,
            'message' => $commit->message,
            'author' => $commit->author,
            'prevHash' => $prevHash,
            'nextHash' => $nextHash,
        ];
    }
}
