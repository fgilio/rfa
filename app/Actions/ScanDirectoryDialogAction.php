<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ScanDirectoryResult;
use Native\Desktop\Dialog;

final readonly class ScanDirectoryDialogAction
{
    public function __construct(
        private ScanDirectoryAction $scan,
    ) {}

    public function handle(): ?ScanDirectoryResult
    {
        $path = app(Dialog::class)
            ->title('Scan Directory for Repositories')
            ->folders()
            ->open();

        if (! $path) {
            return null;
        }

        return $this->scan->handle($path);
    }
}
