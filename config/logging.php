<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    // RFA ships to user machines and keeps logs strictly local. The default
    // resolves to the bounded `daily` channel (info level, 7-day retention) so
    // a release build never grows an unbounded `laravel.log` or accepts
    // debug-level noise. See .claude/skills/wide-events/SKILL.md → Storage.
    'default' => env('LOG_CHANNEL', 'daily'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Every channel below writes to the machine RFA runs on: a rotating file,
    | the process stderr, or nothing at all. The off-box stock channels (slack,
    | papertrail, syslog, errorlog) are absent by design, so no LOG_CHANNEL or
    | LOG_STACK value can route a release build's logs off the user's machine.
    |
    | Available drivers: "single", "daily", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        // A packaged build runs on the env file NativePHP rewrites at build time
        // (ManagesEnvFile::cleanEnvFile), which pins LOG_CHANNEL=stack,
        // LOG_STACK=daily, LOG_DAILY_DAYS=3 and LOG_LEVEL=warning. The stack
        // default matches it so a dev run rotates the same way.
        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'daily')),
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'days' => env('LOG_DAILY_DAYS', 7),
            'replace_placeholders' => true,
        ],

        // Development and CLI runs that stream their logs into the terminal
        // instead of a file, including `php artisan native:run`.
        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

        // Boost browser-log channel pinned to the project's storage so the
        // renderer (which writes through NativePHP's relocated storage_path)
        // and Boost's MCP `browser-logs` tool (which runs CLI artisan and
        // reads the project's path) share one directory. base_path() is
        // unchanged by NativePHP. Pre-defining this overrides Boost's
        // auto-registration (BoostServiceProvider::registerBrowserLogger).
        // The `daily` driver dates each file (`browser-Y-m-d.log`), so console
        // noise from a dev session ages out instead of growing forever.
        'browser' => [
            'driver' => 'daily',
            'path' => base_path('storage/logs/browser.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_BROWSER_DAYS', 14),
        ],

    ],

];
