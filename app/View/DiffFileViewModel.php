<?php

declare(strict_types=1);

namespace App\View;

use App\Enums\AnchorStatus;
use App\Enums\DiffLoadOutcome;
use App\Enums\DiffSide;
use Illuminate\Support\Collection;

/**
 * Reshapes a file's diff data + comments for the diff-file blade.
 *
 * Pulled out of inline @php blocks so the SFC just consumes a normalized struct.
 */
final readonly class DiffFileViewModel
{
    /** @param array{isBinary?: bool, isSymlink?: bool} $file */
    public static function supportsContentCopy(array $file): bool
    {
        return ! ($file['isBinary'] ?? false) && ! ($file['isSymlink'] ?? false);
    }

    /** @param array{isBinary?: bool, isSymlink?: bool} $file */
    public static function showsContentCopy(array $file, ?DiffLoadOutcome $outcome = null): bool
    {
        return self::supportsContentCopy($file) && $outcome !== DiffLoadOutcome::TooLarge;
    }

    /** @param array{status?: string, isExternal?: bool} $file */
    public static function showsDiscard(array $file, bool $allowDiscard, ?string $diffTo): bool
    {
        return $allowDiscard
            && $diffTo === null
            && ($file['status'] ?? '') !== 'commented'
            && ! ($file['isExternal'] ?? false);
    }

    /**
     * Index inline comments by `side:line` for O(1) lookup during line render.
     *
     * @param  list<array<string, mixed>>  $fileComments
     * @return array<string, list<array<string, mixed>>>
     */
    public static function commentsByLine(array $fileComments): array
    {
        $byLine = [];
        foreach ($fileComments as $c) {
            if (($c['side'] ?? null) === DiffSide::File->value) {
                continue;
            }
            if (($c['anchorStatus'] ?? AnchorStatus::Placed->value) === AnchorStatus::Unplaced->value) {
                continue;
            }
            $byLine[self::anchorKeyFor($c)][] = $c;
        }

        return $byLine;
    }

    /**
     * Comments that can't be anchored to a visible line (status === 'unplaced'
     * or the stored line number isn't in the rendered diff).
     *
     * @param  list<array<string, mixed>>  $fileComments
     * @param  array<string, true>  $visibleLineKeys
     * @return Collection<int, array<string, mixed>>
     */
    public static function unplacedInlineComments(array $fileComments, array $visibleLineKeys): Collection
    {
        return collect($fileComments)
            ->where('side', '!=', DiffSide::File->value)
            ->filter(function ($c) use ($visibleLineKeys) {
                if (($c['anchorStatus'] ?? null) === AnchorStatus::Unplaced->value) {
                    return true;
                }

                return ! isset($visibleLineKeys[self::anchorKeyFor($c)]);
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $comment
     */
    public static function anchorKeyFor(array $comment): string
    {
        $side = $comment['side'] ?? DiffSide::Right->value;
        $line = $comment['endLine'] ?? $comment['startLine'] ?? 0;

        return $side.':'.$line;
    }

    /**
     * @param  list<array<string, mixed>>  $hunks
     * @return array{hunks: list<array<string, mixed>>, hasGaps: bool, hasTrailingGap: bool, trailingHiddenCount: int}
     */
    public static function gapSummary(array $hunks, ?int $newFileLineCount): array
    {
        if ($hunks === []) {
            return [
                'hunks' => [],
                'hasGaps' => false,
                'hasTrailingGap' => false,
                'trailingHiddenCount' => 0,
            ];
        }

        $lastHunk = end($hunks);
        $lastHunkEnd = $lastHunk['newStart'] + $lastHunk['newCount'] - 1;
        $hasTrailingGap = $newFileLineCount !== null && $lastHunkEnd < $newFileLineCount;
        $trailingHiddenCount = $hasTrailingGap ? $newFileLineCount - $lastHunkEnd : 0;
        $hasGaps = count($hunks) > 1
            || $hunks[0]['newStart'] > 1
            || $hasTrailingGap;

        return [
            'hunks' => $hunks,
            'hasGaps' => $hasGaps,
            'hasTrailingGap' => $hasTrailingGap,
            'trailingHiddenCount' => $trailingHiddenCount,
        ];
    }
}
