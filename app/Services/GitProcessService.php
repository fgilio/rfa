<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\GitCommandException;
use Symfony\Component\Process\Process;

class GitProcessService
{
    /**
     * Keep Git output parseable regardless of user or repo config.
     *
     * Diff callers still pass explicit flags for readability, but these config
     * values protect lower-level calls such as `git show` and future diffs.
     *
     * @var list<string>
     */
    private const DEFAULT_CONFIG = [
        'core.quotepath=false',
        'diff.noprefix=false',
        'diff.mnemonicPrefix=false',
        'diff.srcPrefix=a/',
        'diff.dstPrefix=b/',
        // Plain removed/added/context colors are pinned alongside the moved
        // variants: DiffParser classifies moved lines by SGR code (2, 33-36),
        // so a user gitconfig theme like `old = magenta` or `new = dim cyan`
        // must not be able to recolor ordinary lines into that range.
        'color.diff.old=red',
        'color.diff.new=green',
        'color.diff.context=normal',
        'color.diff.oldMoved=bold magenta',
        'color.diff.newMoved=bold cyan',
        'color.diff.oldMovedAlternative=bold blue',
        'color.diff.newMovedAlternative=bold yellow',
    ];

    /** @param array<int, string> $args */
    public function run(string $repoPath, array $args): string
    {
        $process = new Process([...$this->baseCommand($repoPath), ...$args]);
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

    /**
     * @return list<string>
     */
    private function baseCommand(string $repoPath): array
    {
        return [
            'git',
            ...collect(self::DEFAULT_CONFIG)
                ->flatMap(fn (string $config): array => ['-c', $config])
                ->all(),
            '-C',
            $repoPath,
        ];
    }
}
