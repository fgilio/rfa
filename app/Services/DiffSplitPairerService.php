<?php

declare(strict_types=1);

namespace App\Services;

class DiffSplitPairerService
{
    /**
     * Pair unified diff lines into side-by-side rows.
     *
     * Context lines emit a row with the same line on both sides. Runs of
     * remove-then-add lines are zipped index-for-index; excess on either
     * side gets a null filler on the other.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array{left: ?array<string, mixed>, right: ?array<string, mixed>}>
     */
    public function pair(array $lines): array
    {
        $rows = [];
        $count = count($lines);
        $i = 0;

        while ($i < $count) {
            $type = $lines[$i]['type'] ?? 'context';

            if ($type === 'context') {
                $rows[] = ['left' => $lines[$i], 'right' => $lines[$i]];
                $i++;

                continue;
            }

            $removes = [];
            while ($i < $count && ($lines[$i]['type'] ?? '') === 'remove') {
                $removes[] = $lines[$i];
                $i++;
            }

            $adds = [];
            while ($i < $count && ($lines[$i]['type'] ?? '') === 'add') {
                $adds[] = $lines[$i];
                $i++;
            }

            $max = max(count($removes), count($adds));
            for ($k = 0; $k < $max; $k++) {
                $rows[] = [
                    'left' => $removes[$k] ?? null,
                    'right' => $adds[$k] ?? null,
                ];
            }
        }

        return $rows;
    }
}
