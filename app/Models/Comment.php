<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'project_id',
        'repo_path',
        'origin_ref',
        'file_path',
        'side',
        'start_line',
        'end_line',
        'file_content_hash',
        'line_snippet',
        'body',
        'is_draft',
        'submitted_at',
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

    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }
}
