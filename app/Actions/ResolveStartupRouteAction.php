<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use Illuminate\Support\Facades\Cache;

final readonly class ResolveStartupRouteAction
{
    private const string CACHE_KEY = 'last-opened-project-slug';

    public function handle(): string
    {
        $lastSlug = Cache::get(self::CACHE_KEY);

        if ($lastSlug && Project::where('slug', $lastSlug)->exists()) {
            return route('review-page', ['slug' => $lastSlug]);
        }

        if ($lastSlug) {
            Cache::forget(self::CACHE_KEY);
        }

        return route('select-repo');
    }

    public function lastOpenedSlug(): ?string
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function rememberLastOpened(string $slug): void
    {
        if (Cache::get(self::CACHE_KEY) === $slug) {
            return;
        }

        Cache::forever(self::CACHE_KEY, $slug);
    }

    public function forgetLastOpened(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
