<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\AgentContextFileKind;
use Carbon\CarbonImmutable;

final readonly class AgentContextFile
{
    public function __construct(
        public string $path,
        public string $absolutePath,
        public AgentContextFileKind $kind,
        public bool $isTracked,
        public bool $isSymlink = false,
        public ?string $symlinkTarget = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $lastEditedAt = null,
        public ?int $lineCount = null,
    ) {}

    public function id(): string
    {
        return self::idFor($this->path);
    }

    /**
     * The id of the file card a context path renders as, for callers that hold
     * a path rather than the file itself.
     */
    public static function idFor(string $path): string
    {
        return 'ctx-'.hash('xxh128', $path);
    }

    public function directory(): string
    {
        $pos = strrpos($this->path, '/');

        return $pos === false ? '' : substr($this->path, 0, $pos);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id(),
            'path' => $this->path,
            'absolutePath' => $this->absolutePath,
            'kind' => $this->kind->value,
            'directory' => $this->directory(),
            'isTracked' => $this->isTracked,
            'isSymlink' => $this->isSymlink,
            'symlinkTarget' => $this->symlinkTarget,
            'createdAt' => $this->createdAt?->toIso8601String(),
            'lastEditedAt' => $this->lastEditedAt?->toIso8601String(),
            'lineCount' => $this->lineCount,
        ];
    }
}
