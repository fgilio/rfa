<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\AgentContextFile;
use App\Services\AgentContextFileScannerService;

final readonly class DiscoverAgentContextFilesAction
{
    public function __construct(
        private AgentContextFileScannerService $scanner,
    ) {}

    /** @return array<int, AgentContextFile> */
    public function handle(string $repoPath): array
    {
        return $this->scanner->scan($repoPath);
    }
}
