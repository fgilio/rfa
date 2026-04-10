<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\ReviewSession;
use Carbon\Carbon;

final readonly class ListProjectsAction
{
    /**
     * @return array{groups: array<string, array<int, array<string, mixed>>>, total: int}
     */
    public function handle(string $sortBy = 'recent', string $search = ''): array
    {
        $projects = Project::query()
            ->select('projects.*')
            ->addSelect([
                'comment_count' => ReviewSession::selectRaw('COALESCE(SUM(JSON_ARRAY_LENGTH(comments)), 0)')
                    ->whereColumn('review_sessions.project_id', 'projects.id')
                    ->whereRaw('JSON_ARRAY_LENGTH(comments) > 0'),
                'last_session_at' => ReviewSession::select('updated_at')
                    ->whereColumn('review_sessions.project_id', 'projects.id')
                    ->orderByDesc('updated_at')
                    ->limit(1),
            ])
            ->get()
            ->map(function ($project) {
                $data = $project->toArray();
                $data['comment_count'] = (int) ($data['comment_count'] ?? 0);

                $lastSessionAt = $data['last_session_at'] ? Carbon::parse($data['last_session_at']) : null;
                $updatedAt = Carbon::parse($project->updated_at);

                $lastActiveAt = $lastSessionAt && $lastSessionAt->greaterThan($updatedAt) ? $lastSessionAt : $updatedAt;
                $data['last_active_at'] = $lastActiveAt->toDateTimeString();
                $data['last_active_ago'] = $lastActiveAt->diffForHumans(short: true);

                unset($data['last_session_at']);

                return $data;
            });

        $total = $projects->count();

        $search = trim($search);
        if ($search !== '') {
            $projects = $projects
                ->map(fn (array $p) => $p + ['_rank' => self::rankMatch($p['name'], $p['branch'] ?? '', $p['path'], $search)])
                ->filter(fn (array $p) => $p['_rank'] < PHP_INT_MAX)
                ->sortBy('_rank');

            $groups = $projects
                ->groupBy('git_common_dir')
                ->map(fn ($group) => $group->map(function (array $p) {
                    unset($p['_rank']);

                    return $p;
                })->values()->all())
                ->all();

            return ['groups' => $groups, 'total' => $total];
        }

        if ($sortBy === 'alpha') {
            $projects = $projects->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            $projects = $projects->sortByDesc('last_active_at');
        }

        $groups = $projects
            ->groupBy('git_common_dir')
            ->when($sortBy === 'recent', function ($groups) {
                return $groups->sortByDesc(fn ($group) => $group->max('last_active_at'));
            })
            ->map(fn ($group) => $group->values()->all())
            ->all();

        return ['groups' => $groups, 'total' => $total];
    }

    /**
     * Rank a project against a search query. Lower = better match. PHP_INT_MAX = no match.
     *
     * Tiers: 0=name exact, 1=name prefix, 2=name word-start, 3=branch exact/prefix,
     * 4=branch word-start, 5=path word-start, 6=name substring, 7=branch substring, 8=path substring.
     */
    public static function rankMatch(string $name, string $branch, string $path, string $query): int
    {
        $q = mb_strtolower($query);
        $n = mb_strtolower($name);
        $b = mb_strtolower($branch);
        $p = mb_strtolower($path);
        $esc = preg_quote($q, '/');
        $ws = '/(?:^|[^a-z0-9])'.$esc.'/';

        if ($n === $q) {
            return 0;
        }
        if (str_starts_with($n, $q)) {
            return 1;
        }
        if (preg_match($ws, $n)) {
            return 2;
        }
        if ($b === $q || str_starts_with($b, $q)) {
            return 3;
        }
        if (preg_match($ws, $b)) {
            return 4;
        }
        if (preg_match($ws, $p)) {
            return 5;
        }
        if (str_contains($n, $q)) {
            return 6;
        }
        if (str_contains($b, $q)) {
            return 7;
        }
        if (str_contains($p, $q)) {
            return 8;
        }

        return PHP_INT_MAX;
    }
}
