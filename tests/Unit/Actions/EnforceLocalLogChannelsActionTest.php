<?php

use App\Actions\EnforceLocalLogChannelsAction;
use Tests\TestCase;

uses(TestCase::class);

test('removes the off-box channels the framework merges back in', function () {
    config([
        'logging.channels' => [
            'daily' => ['driver' => 'daily'],
            'slack' => ['driver' => 'slack'],
            'papertrail' => ['driver' => 'monolog'],
            'syslog' => ['driver' => 'syslog'],
            'errorlog' => ['driver' => 'errorlog'],
        ],
    ]);

    (new EnforceLocalLogChannelsAction)->handle();

    expect(array_keys(config('logging.channels')))->toBe(['daily']);
});

test('leaves a channel set that is already local untouched', function () {
    $channels = [
        'daily' => ['driver' => 'daily'],
        'stderr' => ['driver' => 'monolog'],
    ];

    config(['logging.channels' => $channels]);

    (new EnforceLocalLogChannelsAction)->handle();

    expect(config('logging.channels'))->toBe($channels);
});
