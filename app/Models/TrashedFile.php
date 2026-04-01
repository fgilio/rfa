<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrashedFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrashedFile extends Model
{
    /** @use HasFactory<TrashedFileFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'file_path',
        'file_status',
        'old_path',
        'is_untracked',
        'is_symlink',
        'comments',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_untracked' => 'boolean',
            'is_symlink' => 'boolean',
            'comments' => 'array',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @param Builder<TrashedFile> $query
     *  @return Builder<TrashedFile> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
