<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CompileViewsAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuilds the framework caches while the app is already serving.
 *
 * The Electron main process runs this in the background after a version
 * change, with the config, route, and event cache paths pointed at a
 * staging directory it renames into place afterwards. Views are compiled
 * straight into the live directory without clearing it, which is the
 * difference from `optimize`: `view:cache` empties the directory the
 * running server reads from.
 */
class OptimizeCommand extends Command
{
    protected $signature = 'rfa:optimize';

    protected $description = 'Cache config, events, and routes, then compile every Blade view without clearing the compiled views';

    public function handle(CompileViewsAction $compileViews): int
    {
        Context::flush();

        $startedAt = microtime(true);
        $outcome = 'completed';
        $status = self::FAILURE;

        try {
            $status = $this->call('optimize', ['--except' => 'views']);

            if ($status !== self::SUCCESS) {
                $outcome = 'error';
                Context::add('rfa.reason', 'optimize_failed');

                return $status;
            }

            $views = $compileViews->handle();

            Context::add('rfa.view_path_count', $views['paths']);
            Context::add('rfa.view_count', $views['compiled']);

            $this->components->info(sprintf('Compiled %d Blade templates from %d view roots.', $views['compiled'], $views['paths']));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $outcome = 'error';
            $status = self::FAILURE;
            Context::add('rfa.error_class', $e::class);
            Context::add('rfa.reason', 'view_compile_failed');

            throw $e;
        } finally {
            Context::add('rfa.optimize_status', $status);
            Context::add('rfa.outcome', $outcome);
            Context::add('rfa.duration_ms', (int) round((microtime(true) - $startedAt) * 1000));

            Log::info('framework.optimized');
        }
    }
}
