<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LastViewKind;
use App\Enums\LastViewMode;
use Database\Factories\ReviewSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ?LastViewMode $last_view_mode
 * @property ?LastViewKind $last_view_kind
 * @property ?string $last_view_from
 * @property ?string $last_view_to
 */
class ReviewSession extends Model
{
    /** @use HasFactory<ReviewSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'repo_path',
        'project_id',
        'global_comment',
        'last_view_mode',
        'last_view_kind',
        'last_view_from',
        'last_view_to',
    ];

    protected function casts(): array
    {
        return [
            'last_view_mode' => LastViewMode::class,
            'last_view_kind' => LastViewKind::class,
        ];
    }

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
