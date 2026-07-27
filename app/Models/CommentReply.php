<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommentAuthorType;
use Database\Factories\CommentReplyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommentReply extends Model
{
    /** @use HasFactory<CommentReplyFactory> */
    use HasFactory;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'id',
        'comment_id',
        'author_type',
        'author_key',
        'author_label',
        'body',
    ];

    /** @return BelongsTo<Comment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(Comment::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(
        Builder $query,
        CommentAuthorType $authorType,
        string $authorKey,
    ): Builder {
        return $query
            ->where('author_type', $authorType->value)
            ->where('author_key', $authorKey);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForProjectOrRepo(Builder $query, ?int $projectId, string $repoPath): Builder
    {
        return $query->whereIn(
            'comment_id',
            Comment::query()
                ->forProjectOrRepo($projectId, $repoPath)
                ->select('id'),
        );
    }

    protected function casts(): array
    {
        return [
            'author_type' => CommentAuthorType::class,
        ];
    }
}
