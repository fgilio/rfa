<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Models\ReviewSession;
use Carbon\Carbon;
use Illuminate\Support\Str;

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
                ->map(fn (array $p) => ['rank' => self::rankMatch($p['name'], $p['branch'] ?? '', $p['path'], $search), 'project' => $p])
                ->filter(fn (array $pair) => $pair['rank'] !== null)
                ->sortBy([
                    fn (array $a, array $b) => $a['rank'] <=> $b['rank'],
                    fn (array $a, array $b) => Str::lower($a['project']['name']) <=> Str::lower($b['project']['name']),
                ])
                ->map(fn (array $pair) => $pair['project']);

            $groups = $projects
                ->groupBy('git_common_dir')
                ->map(fn ($group) => $group->values()->all())
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
     * Rank a project against a search query. Lower = better match. null = no match.
     *
     * Score = tier * 10 + field (field: 0=name, 1=branch, 2=path).
     * Tier 0: exact name. Tier 1: starts-with / word-boundary. Tier 2: substring.
     */
    private static function rankMatch(string $name, string $branch, string $path, string $query): ?int
    {
        $lowerQuery = Str::lower($query);
        $lowerName = Str::lower($name);
        $lowerBranch = Str::lower($branch);
        $lowerPath = Str::lower($path);
        $wordBoundaryPattern = '/(?:^|[^a-z0-9])'.preg_quote($lowerQuery, '/').'/';

        // Tier 0: exact name
        if ($lowerName === $lowerQuery) {
            return 0;
        }

        // Tier 1: starts-with or word-boundary (score 10 + field)
        if (str_starts_with($lowerName, $lowerQuery) || preg_match($wordBoundaryPattern, $lowerName)) {
            return 10;
        }
        if (str_starts_with($lowerBranch, $lowerQuery) || preg_match($wordBoundaryPattern, $lowerBranch)) {
            return 11;
        }
        if (preg_match($wordBoundaryPattern, $lowerPath)) {
            return 12;
        }

        // Tier 2: substring (score 20 + field)
        if (str_contains($lowerName, $lowerQuery)) {
            return 20;
        }
        if (str_contains($lowerBranch, $lowerQuery)) {
            return 21;
        }
        if (str_contains($lowerPath, $lowerQuery)) {
            return 22;
        }

        return null;
    }
}
