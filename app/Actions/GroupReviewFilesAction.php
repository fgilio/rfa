<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ReviewFilePair;

final readonly class GroupReviewFilesAction
{
    /**
     * Filter review-file artifacts (.rfa/{timestamp}_comments_{hash}.md) out
     * of a flat file list, returning only the source files.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    public function handle(array $files): array
    {
        return array_values(array_filter(
            $files,
            fn (array $file) => ReviewFilePair::extractBasename($file['path']) === null,
        ));
    }
}
