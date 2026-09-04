<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\DiffTarget;
use App\Enums\DiffLoadOutcome;
use App\Enums\DiffSide;
use App\Enums\LineType;
use App\Services\ReviewConfigService;
use App\Support\DiffCacheKey;

final readonly class BuildDiffContextAction
{
    public function __construct(
        private LoadFileDiffAction $loadFileDiffAction,
        private ReviewConfigService $reviewConfigService,
    ) {}

    /**
     * Build the snippet shown beside each exported comment.
     *
     * The target scopes the diff every snippet is read from, so a commit-range
     * review exports context from that range rather than the working tree. A
     * null target reads the working directory.
     *
     * @param  array<int, array<string, mixed>>  $comments
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, string>
     */
    public function handle(string $repoPath, array $comments, array $files, ?DiffTarget $target = null): array
    {
        $context = [];
        $loaded = [];
        $filesById = collect($files)->keyBy('id');
        $contextKey = ($target ?? DiffTarget::workingDirectory())->contextKey();
        $reviewFingerprint = $this->reviewConfigService->resolve()->cacheFingerprint();

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
                // Read and write through the Action's own cache path rather than
                // reimplementing it: exporting twice used to recompute every miss
                // (a git spawn plus syntax highlighting per file) and throw the
                // result away, because nothing wrote it back.
                $loaded[$fileId] = $this->loadFileDiffAction->handle(
                    $repoPath,
                    $file['path'],
                    $file['isUntracked'] ?? false,
                    cacheKey: DiffCacheKey::for($repoPath, $fileId, $reviewFingerprint, $contextKey.(($file['isWholeFile'] ?? false) ? ':whole-file' : '')),
                    oldPath: $file['oldPath'] ?? null,
                    target: $target,
                    isWholeFile: $file['isWholeFile'] ?? false,
                );
            }

            $diffData = $loaded[$fileId];
            $key = "{$comment['file']}:{$comment['side']}:{$comment['startLine']}:{$comment['endLine']}";

            if ($diffData->outcome === DiffLoadOutcome::TooLarge) {
                $context[$key] = '[Diff skipped: '.DiffLoadOutcome::TooLarge->value.']';

                continue;
            }

            if ($diffData->outcome === DiffLoadOutcome::TransientError) {
                continue;
            }

            $useOld = $comment['side'] === DiffSide::Left->value;
            $lines = [];
            foreach ($diffData->hunks() as $hunk) {
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
