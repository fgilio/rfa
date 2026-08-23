<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\BranchBaseState;
use App\Enums\BranchBaseUnavailableReason;

final readonly class BranchBaseResult
{
    /**
     * @param  list<string>  $hashesInRange  newest-first commit shas in `base..HEAD`; empty unless state is Ready.
     */
    public function __construct(
        public BranchBaseState $state,
        public ?string $baseBranch,
        public ?string $baseSha,
        public array $hashesInRange,
        public ?BranchBaseUnavailableReason $unavailableReason = null,
    ) {}

    public static function notConfigured(): self
    {
        return new self(BranchBaseState::NotConfigured, null, null, []);
    }

    public static function missingRef(string $baseBranch): self
    {
        return new self(BranchBaseState::MissingRef, $baseBranch, null, []);
    }

    public static function onBaseBranch(string $baseBranch): self
    {
        return new self(BranchBaseState::OnBaseBranch, $baseBranch, null, []);
    }

    public static function upToDate(string $baseBranch, string $baseSha): self
    {
        return new self(BranchBaseState::UpToDate, $baseBranch, $baseSha, []);
    }

    /** @param  list<string>  $hashesInRange */
    public static function ready(string $baseBranch, string $baseSha, array $hashesInRange): self
    {
        return new self(BranchBaseState::Ready, $baseBranch, $baseSha, $hashesInRange);
    }

    public static function unavailable(
        string $baseBranch,
        ?string $baseSha,
        BranchBaseUnavailableReason $reason,
    ): self {
        return new self(BranchBaseState::Unavailable, $baseBranch, $baseSha, [], $reason);
    }

    /** @return array{state: string, baseBranch: ?string, baseSha: ?string, hashesInRange: list<string>, commitCount: int, unavailableReason: ?string} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'baseBranch' => $this->baseBranch,
            'baseSha' => $this->baseSha,
            'hashesInRange' => $this->hashesInRange,
            'commitCount' => count($this->hashesInRange),
            'unavailableReason' => $this->unavailableReason?->value,
        ];
    }
}
