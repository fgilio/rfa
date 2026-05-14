<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\RuntimeDiagnosticsService;

final readonly class RecordRuntimeDiagnosticAction
{
    public function __construct(
        private RuntimeDiagnosticsService $diagnostics,
    ) {}

    /** @param array<string, mixed> $context */
    public function handle(string $event, array $context = []): void
    {
        $this->diagnostics->breadcrumb($event, $context);
    }

    /** @param array<string, mixed> $payload */
    public function recordBrowserSample(array $payload): void
    {
        $this->diagnostics->recordBrowserSample($payload);
    }
}
