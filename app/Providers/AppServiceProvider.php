<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\GitFileContentService;
use App\Services\ReviewConfigService;
use App\Support\LocalAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Livewire\Blaze\Blaze;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/rfa.php'), 'rfa');

        // Singleton so hashAt() memoization survives across every caller in a
        // single request. RFA runs as NativePHP / built-in server (shared-nothing),
        // so the cache can't go stale mid-request. Under Octane et al., callers
        // must invoke GitFileContentService::flushCache() between requests.
        $this->app->singleton(GitFileContentService::class);

        // Singleton for the same reason: one request resolves effective review
        // config (config/rfa.php) once and shares the memoized ReviewConfig across
        // every consumer (GitDiffService, FileSourceService, LoadFileDiffAction, …)
        // instead of each rebuilding its own copy.
        $this->app->singleton(ReviewConfigService::class);
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Blade::if('native', fn () => (bool) config('nativephp-internal.running'));
        Blade::if('browser', fn () => ! config('nativephp-internal.running'));
        Blade::directive('localScript', fn (string $expression): string => sprintf(
            '<?php echo %s::script(%s); ?>',
            LocalAsset::class,
            $expression,
        ));

        RateLimiter::for('diagnostics', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($request->ip() ?: 'local'));

        Blaze::optimize()->in(resource_path('views/components'));
    }
}
