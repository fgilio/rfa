<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final readonly class ResolveStartupRouteAction
{
    public const string CACHE_KEY = 'last-opened-project-slug';

    /** @return array{name: string, params: array<string, string>} */
    public function handle(): array
    {
        $lastSlug = Cache::get(self::CACHE_KEY);

        if ($lastSlug && Project::where('slug', $lastSlug)->exists()) {
            return ['name' => 'review-page', 'params' => ['slug' => $lastSlug]];
        }

        if ($lastSlug) {
            Cache::forget(self::CACHE_KEY);
        }

        $mostRecent = Project::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($mostRecent) {
            return ['name' => 'review-page', 'params' => ['slug' => $mostRecent->slug]];
        }

        return ['name' => 'no-projects', 'params' => []];
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
