<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final readonly class ResolveStartupRouteAction
{
    public const string CACHE_KEY = 'last-opened-project-slug';

    public function handle(): string
    {
        $lastSlug = Cache::get(self::CACHE_KEY);

        if ($lastSlug && Project::where('slug', $lastSlug)->exists()) {
            return route('review-page', ['slug' => $lastSlug]);
        }

        if ($lastSlug) {
            Cache::forget(self::CACHE_KEY);
        }

        $mostRecent = Project::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($mostRecent) {
            return route('review-page', ['slug' => $mostRecent->slug]);
        }

        return route('no-projects');
    }

    /** Clears the last-opened slug if it matches. Returns true when cleared. */
    public function forgetIfLastOpened(string $slug): bool
    {
        if (Cache::get(self::CACHE_KEY) !== $slug) {
            return false;
        }

        Cache::forget(self::CACHE_KEY);

        return true;
    }
}
