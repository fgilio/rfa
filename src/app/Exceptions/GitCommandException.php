<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class GitCommandException extends RuntimeException
{
    public function __construct(
        public readonly string $command,
        public readonly string $stderr,
        public readonly int $exitCode,
    ) {
        parent::__construct("Git command failed (exit {$exitCode}): {$command}", $exitCode);
    }
}
