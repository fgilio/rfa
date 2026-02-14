<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewSession extends Model
{
    protected $fillable = ['repo_path', 'viewed_files', 'comments', 'global_comment'];

    protected function casts(): array
    {
        return [
            'viewed_files' => 'array',
            'comments' => 'array',
        ];
    }
}
