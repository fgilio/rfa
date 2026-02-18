<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DiffSide;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;

final readonly class BuildDiffContextAction
{
    public function __construct(
        private LoadFileDiffAction $loadFileDiffAction,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, string>
     */
    public function handle(string $repoPath, array $comments, array $files): array
    {
        $context = [];
        $loaded = [];
        $filesById = collect($files)->keyBy('id');

        foreach ($comments as $comment) {
            if ($comment['startLine'] === null) {
                continue;
            }

            $file = $filesById->get($comment['fileId']);
            if (! $file) {
                continue;
            }

            $fileId = $file['id'];

            if (! array_key_exists($fileId, $loaded)) {
                $cacheKey = DiffCacheKey::for($repoPath, $fileId);
                $loaded[$fileId] = Cache::get($cacheKey)
                    ?? $this->loadFileDiffAction->handle($repoPath, $file['path'], $file['isUntracked'] ?? false);
            }

            $diffData = $loaded[$fileId];
            if (! $diffData || ($diffData['tooLarge'] ?? false)) {
                continue;
            }

            $useOld = $comment['side'] === DiffSide::Left->value;
            $lines = [];
            foreach ($diffData['hunks'] as $hunk) {
                foreach ($hunk['lines'] as $line) {
                    $lineNum = $useOld
                        ? ($line['oldLineNum'] ?? $line['newLineNum'])
                        : ($line['newLineNum'] ?? $line['oldLineNum']);
                    if ($lineNum === null) {
                        continue;
                    }
                    if ($lineNum >= $comment['startLine'] && $lineNum <= ($comment['endLine'] ?? $comment['startLine'])) {
                        $prefix = match ($line['type']) {
                            'add' => '+',
                            'remove' => '-',
                            default => ' ',
                        };
                        $lines[] = $prefix.$line['content'];
                    }
                }
            }

            $key = "{$comment['file']}:{$comment['startLine']}:{$comment['endLine']}";
            $context[$key] = implode("\n", $lines);
        }

        return $context;
    }
}
