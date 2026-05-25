<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BranchExplorerSelectionKind;

final readonly class BranchExplorerSelectionResult
{
    public function __construct(
        public BranchExplorerSelectionKind $kind,
        public ?string $url = null,
        public ?string $message = null,
    ) {}

    public static function noop(): self
    {
        return new self(BranchExplorerSelectionKind::Noop);
    }

    public static function navigate(string $url): self
    {
        return new self(BranchExplorerSelectionKind::Navigate, url: $url);
    }

    public static function error(string $message): self
    {
        return new self(BranchExplorerSelectionKind::Error, message: $message);
    }

    public static function stale(string $message): self
    {
        return new self(BranchExplorerSelectionKind::Stale, message: $message);
    }

    public function shouldNavigate(): bool
    {
        return $this->kind === BranchExplorerSelectionKind::Navigate && $this->url !== null;
    }

    public function isNoop(): bool
    {
        return $this->kind === BranchExplorerSelectionKind::Noop;
    }

    public function isStale(): bool
    {
        return $this->kind === BranchExplorerSelectionKind::Stale;
    }

    public function isError(): bool
    {
        return $this->kind === BranchExplorerSelectionKind::Error;
    }

    /** @return array{kind: string, url: ?string, message: ?string} */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'url' => $this->url,
            'message' => $this->message,
        ];
    }
}
