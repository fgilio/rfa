<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewedFile extends Model
{
    protected $fillable = [
        'project_id',
        'repo_path',
        'file_path',
        'content_hash',
    ];

    /**
     * Scope a query to a project (when known) or to a bare repo path.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProjectOrRepo(Builder $query, ?int $projectId, string $repoPath): Builder
    {
        return $projectId
            ? $query->where('project_id', $projectId)
            : $query->whereNull('project_id')->where('repo_path', $repoPath);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
