<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TrashedFile extends Model
{
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

    /** @param Builder<TrashedFile> $query
     *  @return Builder<TrashedFile> */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }
}
