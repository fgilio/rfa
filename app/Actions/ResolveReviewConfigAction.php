<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ReviewConfig;
use App\Services\ReviewConfigService;

/**
 * Hand the effective review configuration to interfaces that cannot reach
 * ReviewConfigService themselves (Livewire components, console tooling), so
 * every layer bases cache identity and TTLs on the same coerced values.
 */
final readonly class ResolveReviewConfigAction
{
    public function __construct(
        private ReviewConfigService $reviewConfigService,
    ) {}

    public function handle(): ReviewConfig
    {
        return $this->reviewConfigService->resolve();
    }
}
