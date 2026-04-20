<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\ProjectListResult;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ReviewSession;
use App\Services\ProjectSearchRanker;
use Carbon\Carbon;
use Illuminate\Support\Str;

final readonly class ListProjectsAction
{
    public function __construct(
        private ProjectSearchRanker $ranker,
    ) {}

    public function handle(string $sortBy = 'recent', string $search = ''): ProjectListResult
    {
        $projects = Project::query()
            ->select('projects.*')
            ->addSelect([
                'comment_count' => Comment::selectRaw('COUNT(*)')
                    ->whereColumn('comments.project_id', 'projects.id')
                    ->whereNull('submitted_at'),
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
                ->map(fn (array $p) => ['rank' => $this->ranker->rank($p['name'], $p['branch'] ?? '', $p['path'], $search), 'project' => $p])
                ->filter(fn (array $pair) => $pair['rank'] !== null)
                ->sortBy([
                    fn (array $a, array $b) => $a['rank'] <=> $b['rank'],
                    fn (array $a, array $b) => Str::lower($a['project']['name']) <=> Str::lower($b['project']['name']),
                ])
                ->map(fn (array $pair) => $pair['project']);

            $matchCount = $projects->count();

            $groups = $projects
                ->groupBy('git_common_dir')
                ->map(fn ($group) => $group->values()->all())
                ->all();

            return new ProjectListResult($groups, $total, $matchCount);
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

        return new ProjectListResult($groups, $total, $total);
    }
}
