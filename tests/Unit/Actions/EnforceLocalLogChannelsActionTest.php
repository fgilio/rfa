<?php

use App\Actions\EnforceLocalLogChannelsAction;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Tests\TestCase;

uses(TestCase::class);

/** The stock channel shapes, as Laravel merges them back over config/logging.php. */
test('removes the off-box channels the framework merges back in', function () {
    config([
        'logging.channels' => [
            'daily' => ['driver' => 'daily'],
            'slack' => ['driver' => 'slack', 'url' => 'https://hooks.slack.test/x'],
            'papertrail' => [
                'driver' => 'monolog',
                'handler' => SyslogUdpHandler::class,
                'handler_with' => ['host' => 'logs.papertrail.test', 'port' => 1234],
            ],
            'syslog' => ['driver' => 'syslog'],
            'errorlog' => ['driver' => 'errorlog'],
        ],
    ]);

    (new EnforceLocalLogChannelsAction)->handle();

    expect(array_keys(config('logging.channels')))->toBe(['daily']);
});

test('removes a monolog channel that names a network destination', function () {
    config([
        'logging.channels' => [
            'shipper' => [
                'driver' => 'monolog',
                'handler' => StreamHandler::class,
                'handler_with' => ['url' => 'https://logs.example.test'],
            ],
        ],
    ]);

    (new EnforceLocalLogChannelsAction)->handle();

    expect(config('logging.channels'))->toBe([]);
});

test('leaves a channel set that is already local untouched', function () {
    $channels = [
        'daily' => ['driver' => 'daily'],
        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'handler_with' => ['stream' => 'php://stderr'],
        ],
    ];

    config(['logging.channels' => $channels]);

    (new EnforceLocalLogChannelsAction)->handle();

    expect(config('logging.channels'))->toBe($channels);
});
