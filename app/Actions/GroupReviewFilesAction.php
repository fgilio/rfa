<?php

declare(strict_types=1);

namespace App\Actions;

use App\Services\ReviewStateService;

final readonly class GroupReviewFilesAction
{
    public function __construct(
        private ReviewStateService $reviewStateService,
    ) {}

    /**
     * Filter review-file artifacts (.rfa/{timestamp}_comments_{hash}.md) out
     * of a flat file list, returning only the source files.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $files): array
    {
        return $this->reviewStateService->sourceFiles($files);
    }
}
