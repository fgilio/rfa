<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\File;

final readonly class DeleteReviewFilesAction
{
    private const BASENAME_PATTERN = '/^\d{8}_\d{6}_comments_[A-Za-z0-9]+$/';

    /**
     * Delete review file pair(s) by basename.
     *
     * @param  string|array<int, string>  $basenames
     * @return int Number of files deleted
     */
    public function handle(string $repoPath, string|array $basenames): int
    {
        $basenames = is_string($basenames) ? [$basenames] : $basenames;
        $deleted = 0;

        foreach ($basenames as $basename) {
            if (! preg_match(self::BASENAME_PATTERN, $basename)) {
                continue;
            }

            $rfaDir = $repoPath.'/.rfa';

            foreach (['json', 'md'] as $ext) {
                $path = $rfaDir.'/'.$basename.'.'.$ext;

                if (File::exists($path)) {
                    File::delete($path);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
