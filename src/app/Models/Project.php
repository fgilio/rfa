<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'path',
        'git_common_dir',
        'is_worktree',
        'branch',
        'global_gitignore_path',
        'respect_global_gitignore',
    ];

    protected function casts(): array
    {
        return [
            'is_worktree' => 'boolean',
            'respect_global_gitignore' => 'boolean',
        ];
    }
}
