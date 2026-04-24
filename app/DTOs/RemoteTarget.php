<?php

declare(strict_types=1);

namespace App\DTOs;

use InvalidArgumentException;

/**
 * Construct only via the static factories — the builder relies on the shape
 * of `$params` they produce.
 */
final readonly class RemoteTarget
{
    public const TYPE_REPO = 'repo';

    public const TYPE_BRANCH = 'branch';

    public const TYPE_COMMIT = 'commit';

    public const TYPE_FILE = 'file';

    public const TYPE_LINE = 'line';

    /** @param array<string, mixed> $params */
    private function __construct(
        public string $type,
        public array $params,
    ) {}

    public static function repo(): self
    {
        return new self(self::TYPE_REPO, []);
    }

    public static function branch(string $name): self
    {
        if ($name === '') {
            throw new InvalidArgumentException('Branch name cannot be empty');
        }

        return new self(self::TYPE_BRANCH, ['name' => $name]);
    }

    public static function commit(string $sha): self
    {
        if ($sha === '') {
            throw new InvalidArgumentException('Commit sha cannot be empty');
        }

        return new self(self::TYPE_COMMIT, ['sha' => $sha]);
    }

    public static function file(string $ref, string $path): self
    {
        if ($ref === '' || $path === '') {
            throw new InvalidArgumentException('File target requires a non-empty ref and path');
        }

        return new self(self::TYPE_FILE, ['ref' => $ref, 'path' => $path]);
    }

    public static function line(string $ref, string $path, int $start, ?int $end = null): self
    {
        if ($ref === '' || $path === '') {
            throw new InvalidArgumentException('Line target requires a non-empty ref and path');
        }

        if ($end !== null && $end < $start) {
            [$start, $end] = [$end, $start];
        }
        if ($end === $start) {
            $end = null;
        }

        if ($start < 1 || ($end !== null && $end < 1)) {
            throw new InvalidArgumentException('Line numbers must be >= 1');
        }

        return new self(self::TYPE_LINE, [
            'ref' => $ref,
            'path' => $path,
            'start' => $start,
            'end' => $end,
        ]);
    }

    /** @return array{type: string, params: array<string, mixed>} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'params' => $this->params];
    }

    /** @param array<string, mixed> $params */
    public static function fromWire(string $type, array $params): self
    {
        return match ($type) {
            self::TYPE_REPO => self::repo(),
            self::TYPE_BRANCH => self::branch((string) ($params['name'] ?? '')),
            self::TYPE_COMMIT => self::commit((string) ($params['sha'] ?? '')),
            self::TYPE_FILE => self::file((string) ($params['ref'] ?? ''), (string) ($params['path'] ?? '')),
            self::TYPE_LINE => self::line(
                (string) ($params['ref'] ?? ''),
                (string) ($params['path'] ?? ''),
                (int) ($params['start'] ?? 0),
                isset($params['end']) ? (int) $params['end'] : null,
            ),
            default => throw new InvalidArgumentException("Unknown remote target type: {$type}"),
        };
    }
}
