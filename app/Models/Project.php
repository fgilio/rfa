<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\ProjectObserver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(ProjectObserver::class)]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'path',
        'git_common_dir',
        'is_worktree',
        'branch',
        'remote_url',
        'global_gitignore_path',
        'respect_global_gitignore',
        'default_base_branch',
    ];

    /** @return HasMany<ReviewSession, $this> */
    public function reviewSessions(): HasMany
    {
        return $this->hasMany(ReviewSession::class);
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<ReviewedFile, $this> */
    public function reviewedFiles(): HasMany
    {
        return $this->hasMany(ReviewedFile::class);
    }

    /** @return HasMany<TrashedFile, $this> */
    public function trashedFiles(): HasMany
    {
        return $this->hasMany(TrashedFile::class);
    }

    protected function casts(): array
    {
        return [
            'is_worktree' => 'boolean',
            'respect_global_gitignore' => 'boolean',
        ];
    }
}
