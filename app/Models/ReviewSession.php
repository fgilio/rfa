<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewSession extends Model
{
    protected $fillable = ['repo_path', 'project_id', 'context_fingerprint', 'viewed_files', 'comments', 'global_comment'];

    /** @return array<string, int|string> */
    public static function scopeKey(string $repoPath, ?int $projectId, string $contextFingerprint): array
    {
        return $projectId
            ? ['project_id' => $projectId, 'context_fingerprint' => $contextFingerprint]
            : ['repo_path' => $repoPath, 'context_fingerprint' => $contextFingerprint];
    }

    protected function casts(): array
    {
        return [
            'viewed_files' => 'array',
            'comments' => 'array',
        ];
    }
}
