<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ReviewFilePair;
use Illuminate\Support\Facades\File;

final readonly class DeleteReviewFilesAction
{
    /**
     * Delete review file(s) by basename.
     *
     * @param  string|array<int, string>  $basenames
     * @return int Number of files deleted
     */
    public function handle(string $repoPath, string|array $basenames): int
    {
        $basenames = is_string($basenames) ? [$basenames] : $basenames;
        $deleted = 0;

        foreach ($basenames as $basename) {
            if (! ReviewFilePair::isValidBasename($basename)) {
                continue;
            }

            if (File::delete($repoPath.'/.rfa/'.$basename.'.md')) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
