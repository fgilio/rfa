<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DiffSide;
use App\Enums\LineType;
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
                $cached = Cache::get(DiffCacheKey::for($repoPath, $fileId));
                $loaded[$fileId] = DiffCacheKey::isCurrentShape($cached)
                    ? $cached
                    : $this->loadFileDiffAction->handle($repoPath, $file['path'], $file['isUntracked'] ?? false, oldPath: $file['oldPath'] ?? null);
            }

            $diffData = $loaded[$fileId];
            $key = "{$comment['file']}:{$comment['side']}:{$comment['startLine']}:{$comment['endLine']}";

            if (($diffData['tooLarge'] ?? false)) {
                $reason = $diffData['skipReason'] ?? 'too-large';
                $context[$key] = "[Diff skipped: {$reason}]";

                continue;
            }

            if (array_key_exists('error', $diffData)) {
                continue;
            }

            $useOld = $comment['side'] === DiffSide::Left->value;
            $lines = [];
            foreach ($diffData['hunks'] as $hunk) {
                foreach ($hunk['lines'] as $line) {
                    // Use the line number for the comment's own side only. Falling back
                    // to the other side would pull in lines absent from this side. For example,
                    // an Add line (oldLineNum=null, newLineNum=41) would match a left-side
                    // range of old lines 40-42 and be emitted into the old-side snippet,
                    // despite having no presence on the left at all.
                    $lineNum = $useOld ? $line['oldLineNum'] : $line['newLineNum'];
                    if ($lineNum === null) {
                        continue;
                    }
                    if ($lineNum >= $comment['startLine'] && $lineNum <= ($comment['endLine'] ?? $comment['startLine'])) {
                        $prefix = match ($line['type']) {
                            LineType::Add => '+',
                            LineType::Remove => '-',
                            default => ' ',
                        };
                        $lines[] = $prefix.$line['content'];
                    }
                }
            }

            $context[$key] = implode("\n", $lines);
        }

        return $context;
    }
}
