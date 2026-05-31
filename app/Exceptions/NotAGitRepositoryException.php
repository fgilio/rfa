<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a path the user tried to open is not inside a git repository.
 *
 * Distinct from a generic RuntimeException so callers can tell this expected,
 * user-facing condition apart from an infrastructure failure (e.g. a database
 * error, which also extends RuntimeException) — catching the broad type would
 * mislabel a DB failure as "not a git repository".
 */
final class NotAGitRepositoryException extends RuntimeException
{
    public static function for(string $path): self
    {
        return new self("Not a git repository: {$path}");
    }
}
