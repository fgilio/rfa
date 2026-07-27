<?php

declare(strict_types=1);

namespace App\Console\Benchmark;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

final class BenchmarkIsolation
{
    public const ENV_ENABLED = 'RFA_BENCHMARK_ISOLATED';

    public const ENV_DATABASE = 'RFA_BENCHMARK_DB';

    public const ENV_APP_DATABASE = 'RFA_BENCHMARK_APP_DB';

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * @return array<string, string>
     */
    public function createEnvironment(): array
    {
        $databasePath = $this->createDatabasePath();

        return [
            self::ENV_ENABLED => '1',
            self::ENV_DATABASE => $databasePath,
            self::ENV_APP_DATABASE => (string) config('database.connections.sqlite.database'),
        ];
    }

    public function activate(): string
    {
        $databasePath = getenv(self::ENV_DATABASE) ?: '';

        if ($databasePath === '' && $this->app->environment('testing')) {
            return (string) config('database.connections.sqlite.database');
        }

        $createdForThisProcess = false;

        if ($databasePath === '') {
            $databasePath = $this->createDatabasePath();
            $createdForThisProcess = true;
        }

        $this->guardDatabasePath($databasePath, $this->appDatabasePath());

        if (! is_file($databasePath) && @touch($databasePath) === false) {
            throw new RuntimeException("Unable to create benchmark database at {$databasePath}");
        }

        $this->applyRuntimeConfiguration($databasePath);
        $this->resetResolvedServices();

        DB::purge('sqlite');
        $this->app['db']->setDefaultConnection('sqlite');
        DB::reconnect('sqlite');
        Model::setConnectionResolver($this->app['db']);

        Artisan::call('migrate', ['--force' => true]);

        if ($createdForThisProcess) {
            register_shutdown_function(function () use ($databasePath): void {
                $this->cleanupDatabase($databasePath);
            });
        }

        return $databasePath;
    }

    public function ensureActive(): void
    {
        if ($this->isActive()) {
            return;
        }

        throw new RuntimeException('Benchmark isolation is not active. Refusing to touch app data.');
    }

    public function cleanupDatabase(string $databasePath): void
    {
        if (! $this->isSafeBenchmarkDatabase($databasePath)) {
            return;
        }

        if (is_file($databasePath)) {
            @unlink($databasePath);
        }
    }

    private function isActive(): bool
    {
        if ((string) getenv(self::ENV_ENABLED) === '1') {
            return $this->isSafeBenchmarkDatabase(
                (string) config('database.connections.sqlite.database'),
                $this->appDatabasePath()
            );
        }

        return $this->app->environment('testing');
    }

    private function applyRuntimeConfiguration(string $databasePath): void
    {
        putenv(self::ENV_ENABLED.'=1');
        putenv(self::ENV_DATABASE.'='.$databasePath);
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE='.$databasePath);
        putenv('CACHE_STORE=array');
        putenv('SESSION_DRIVER=array');
        putenv('QUEUE_CONNECTION=sync');

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $databasePath,
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
        ]);
    }

    private function resetResolvedServices(): void
    {
        foreach (['cache', 'cache.store', 'db', 'db.factory', 'queue', 'session', 'session.store'] as $service) {
            $this->app->forgetInstance($service);
            Facade::clearResolvedInstance($service);
        }
    }

    private function createDatabasePath(): string
    {
        $tempDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $path = $tempDirectory.DIRECTORY_SEPARATOR.'rfa-benchmark-'.bin2hex(random_bytes(16)).'.sqlite';
            $handle = @fopen($path, 'x');

            if ($handle === false) {
                continue;
            }

            fclose($handle);

            return $path;
        }

        throw new RuntimeException('Unable to allocate benchmark database path.');
    }

    private function guardDatabasePath(string $databasePath, ?string $appDatabasePath = null): void
    {
        if (! $this->isSafeBenchmarkDatabase($databasePath, $appDatabasePath)) {
            throw new RuntimeException("Unsafe benchmark database path: {$databasePath}");
        }
    }

    private function isSafeBenchmarkDatabase(string $databasePath, ?string $appDatabasePath = null): bool
    {
        $directory = $this->normalizePath(dirname($databasePath));
        $tempDirectory = $this->normalizePath(sys_get_temp_dir());
        $appDatabaseRealPath = $appDatabasePath !== null && $appDatabasePath !== ''
            ? $this->normalizePath($appDatabasePath)
            : null;
        $databaseRealPath = $this->normalizePath($databasePath);

        if ($directory === null || $tempDirectory === null || $directory !== $tempDirectory) {
            return false;
        }

        if (! str_starts_with(basename($databasePath), 'rfa-benchmark-')) {
            return false;
        }

        if ($databaseRealPath !== null && $appDatabaseRealPath !== null && $databaseRealPath === $appDatabaseRealPath) {
            return false;
        }

        return true;
    }

    private function appDatabasePath(): ?string
    {
        $path = getenv(self::ENV_APP_DATABASE) ?: (string) config('database.connections.sqlite.database');

        return $path !== '' ? $path : null;
    }

    private function normalizePath(string $path): ?string
    {
        $realPath = realpath($path);

        if ($realPath === false) {
            return null;
        }

        return preg_replace('/^\/private(?=\/)/', '', $realPath);
    }
}
