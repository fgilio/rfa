<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReviewSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewSession extends Model
{
    /** @use HasFactory<ReviewSessionFactory> */
    use HasFactory;

    protected $fillable = ['repo_path', 'project_id', 'context_fingerprint', 'reviewed_files', 'comments', 'global_comment'];

    /** @return array<string, int|string> */
    public static function lookupKey(string $repoPath, ?int $projectId, string $contextFingerprint): array
    {
        return $projectId
            ? ['project_id' => $projectId, 'context_fingerprint' => $contextFingerprint]
            : ['repo_path' => $repoPath, 'context_fingerprint' => $contextFingerprint];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    protected function casts(): array
    {
        return [
            'reviewed_files' => 'array',
            'comments' => 'array',
        ];
    }
}
