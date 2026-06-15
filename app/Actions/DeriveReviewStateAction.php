<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ReviewState;
use App\Services\ReviewStateService;

final readonly class DeriveReviewStateAction
{
    public function __construct(
        private ReviewStateService $reviewStateService,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @param  array<string, mixed>  $reviewedFiles
     */
    public function handle(array $files, array $reviewedFiles = [], ?string $selectedFileId = null, string $fileFilter = '', bool $hideReviewed = false): ReviewState
    {
        return $this->reviewStateService->derive(
            files: $files,
            reviewedFiles: $reviewedFiles,
            selectedFileId: $selectedFileId,
            fileFilter: $fileFilter,
            hideReviewed: $hideReviewed,
        );
    }
}
