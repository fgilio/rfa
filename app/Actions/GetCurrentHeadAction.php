<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CurrentHeadResult;
use App\Exceptions\GitCommandException;
use App\Services\GitMetadataService;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

final readonly class GetCurrentHeadAction
{
    public function __construct(
        private GitMetadataService $gitMetadataService,
    ) {}

    public function handle(string $repoPath, ?string $targetBranch = null): CurrentHeadResult
    {
        try {
            $rawBranch = $this->gitMetadataService->getCurrentBranch($repoPath);
            $sha = $this->gitMetadataService->getHeadSha($repoPath);
        } catch (GitCommandException|ProcessTimedOutException) {
            // Timeouts are transient (git lock, fs stall). Return the sentinel
            // so the caller's 2s poll retries next tick instead of 500ing.
            return new CurrentHeadResult(branch: null, sha: '', detached: false);
        }

        $detached = $rawBranch === '' || $rawBranch === 'HEAD';

        $targetExists = null;

        if ($targetBranch !== null && $targetBranch !== '') {
            $targetExists = $this->gitMetadataService->branchExists($repoPath, $targetBranch);
        }

        return new CurrentHeadResult(
            branch: $detached ? null : $rawBranch,
            sha: $sha,
            detached: $detached,
            targetExists: $targetExists,
        );
    }
}
