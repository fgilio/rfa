<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TrashedFileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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

    /**
     * Delete the on-disk content blob whenever the row is deleted, so a
     * trashed file's content can never outlive its record. This is the single
     * source of truth for blob cleanup — every deletion path must go through a
     * model delete (NOT a query-builder bulk delete or a DB-level cascade,
     * both of which bypass model events). Project deletion is handled in
     * ProjectObserver::deleting for exactly that reason.
     */
    protected static function booted(): void
    {
        static::deleting(function (TrashedFile $trashedFile): void {
            Storage::delete($trashedFile->blobPath());
        });
    }

    public function blobPath(): string
    {
        return "trash/{$this->id}";
    }

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
