<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/rfa.php'), 'rfa');
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Blade::if('native', fn () => (bool) config('nativephp-internal.running'));
        Blade::if('browser', fn () => ! config('nativephp-internal.running'));
    }
}
