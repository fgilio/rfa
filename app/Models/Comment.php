<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /** Sentinel for `origin_ref` rows owned by the Context page. */
    public const ORIGIN_CONTEXT = 'context-file';

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

    /**
     * Scope a query to comments not yet exported. Catches both drafts and
     * saved-but-not-exported rows — both states represent work that would be
     * lost if the user forgets.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnsubmitted(Builder $query): Builder
    {
        return $query->whereNull('submitted_at');
    }

    /**
     * Scope a query to comments that originate on the Context page (the
     * CLAUDE.md / AGENTS.md inventory).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFromContext(Builder $query): Builder
    {
        return $query->where('origin_ref', self::ORIGIN_CONTEXT);
    }

    /**
     * Scope a query to comments that originate on the Review page (anything
     * not stamped with the context-file sentinel).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFromReview(Builder $query): Builder
    {
        return $query->where('origin_ref', '!=', self::ORIGIN_CONTEXT);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<CommentReply, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(CommentReply::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'is_draft' => 'boolean',
            'submitted_at' => 'datetime',
        ];
    }
}
