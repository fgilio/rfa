<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GitCommandException;
use Symfony\Component\Process\Process;

final class GitProcessService
{
    /** @param array<int, string> $args */
    public function run(string $repoPath, array $args): string
    {
        $process = new Process(['git', '-c', 'core.quotepath=false', '-C', $repoPath, ...$args]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new GitCommandException(
                command: 'git '.implode(' ', $args),
                stderr: trim($process->getErrorOutput()),
                exitCode: $process->getExitCode() ?? 1,
            );
        }

        return $process->getOutput();
    }
}
