<?php

use App\Models\Project;
use App\Models\ReviewSession;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->originalDbConnection = getenv('DB_CONNECTION') ?: null;
    $this->originalDbDatabase = getenv('DB_DATABASE') ?: null;
    $this->originalCacheStore = getenv('CACHE_STORE') ?: null;
    $this->originalSessionDriver = getenv('SESSION_DRIVER') ?: null;
    $this->originalQueueConnection = getenv('QUEUE_CONNECTION') ?: null;
    $this->originalSqliteDatabase = config('database.connections.sqlite.database');
    $this->originalCacheDefault = config('cache.default');
    $this->originalSessionDriverConfig = config('session.driver');
    $this->originalQueueDefault = config('queue.default');

    $this->appDatabasePath = tempnam(sys_get_temp_dir(), 'rfa-app-db-');

    if ($this->appDatabasePath === false) {
        throw new RuntimeException('Unable to allocate app database path for benchmark test.');
    }

    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$this->appDatabasePath);
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('QUEUE_CONNECTION=sync');

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $this->appDatabasePath,
        'cache.default' => 'array',
        'session.driver' => 'array',
        'queue.default' => 'sync',
    ]);

    DB::purge('sqlite');
    $this->app['db']->setDefaultConnection('sqlite');
    DB::reconnect('sqlite');

    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    DB::disconnect('sqlite');

    $restore = function (string $key, string|false|null $value): void {
        if ($value === null || $value === false || $value === '') {
            putenv($key);

            return;
        }

        putenv("{$key}={$value}");
    };

    $restore('DB_CONNECTION', $this->originalDbConnection);
    $restore('DB_DATABASE', $this->originalDbDatabase);
    $restore('CACHE_STORE', $this->originalCacheStore);
    $restore('SESSION_DRIVER', $this->originalSessionDriver);
    $restore('QUEUE_CONNECTION', $this->originalQueueConnection);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $this->originalSqliteDatabase,
        'cache.default' => $this->originalCacheDefault,
        'session.driver' => $this->originalSessionDriverConfig,
        'queue.default' => $this->originalQueueDefault,
    ]);

    DB::purge('sqlite');

    if (is_file($this->appDatabasePath)) {
        @unlink($this->appDatabasePath);
    }
});

test('benchmark command does not delete app projects or review sessions', function () {
    $project = Project::create([
        'slug' => 'keep-me',
        'name' => 'Keep Me',
        'path' => '/tmp/keep-me',
        'git_common_dir' => '/tmp/keep-me/.git',
        'branch' => 'main',
    ]);

    ReviewSession::create([
        'repo_path' => '/tmp/keep-me',
        'project_id' => $project->id,
        'context_fingerprint' => 'working',
        'viewed_files' => ['app.php' => 'hash'],
        'comments' => [['id' => 'comment-1', 'file' => 'app.php']],
        'global_comment' => 'Keep this session',
    ]);

    $this->artisan('rfa:benchmark-perf', [
        '--json' => true,
        '--samples' => 1,
        '--warmup-samples' => 0,
        '--rounds' => 1,
        '--warmup-rounds' => 0,
    ])->assertExitCode(0);

    DB::purge('sqlite');
    $this->app['db']->setDefaultConnection('sqlite');
    DB::reconnect('sqlite');

    expect(Project::query()->pluck('slug')->all())->toBe(['keep-me']);
    expect(ReviewSession::query()->count())->toBe(1);
    expect(ReviewSession::query()->value('global_comment'))->toBe('Keep this session');
});
