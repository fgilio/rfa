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

    protected $fillable = ['repo_path', 'project_id', 'global_comment'];

    /** @return array<string, int|string|null> */
    public static function lookupKey(string $repoPath, ?int $projectId): array
    {
        return $projectId
            ? ['project_id' => $projectId]
            : ['project_id' => null, 'repo_path' => $repoPath];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
