<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ListProjectsAction
{
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function handle(string $sortBy = 'recent'): array
    {
        $projects = Project::query()
            ->select('projects.*')
            ->addSelect(DB::raw(
                '(SELECT COALESCE(SUM(JSON_ARRAY_LENGTH(comments)), 0) FROM review_sessions WHERE review_sessions.project_id = projects.id AND JSON_ARRAY_LENGTH(comments) > 0) as comment_count'
            ))
            ->addSelect(DB::raw(
                '(SELECT MAX(review_sessions.updated_at) FROM review_sessions WHERE review_sessions.project_id = projects.id) as last_session_at'
            ))
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

        if ($sortBy === 'alpha') {
            $projects = $projects->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE);
        } else {
            $projects = $projects->sortByDesc('last_active_at');
        }

        return $projects
            ->groupBy('git_common_dir')
            ->when($sortBy === 'recent', function ($groups) {
                return $groups->sortByDesc(fn ($group) => $group->max('last_active_at'));
            })
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }
}
