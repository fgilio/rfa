<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Arr;

final readonly class EnforceLocalLogChannelsAction
{
    /**
     * Stock Laravel channels that write to an off-box destination.
     *
     * @var list<string>
     */
    public const REMOTE_CHANNELS = ['slack', 'papertrail', 'syslog', 'errorlog'];

    /**
     * Drop the off-box channels from the resolved logging configuration.
     *
     * Laravel merges its own `logging.channels` over the application's
     * (LoadConfiguration::mergeableOptions), so omitting a channel from
     * config/logging.php leaves it selectable through LOG_CHANNEL or LOG_STACK.
     * Removing the channels here leaves the environment nothing off-box to name.
     */
    public function handle(): void
    {
        config([
            'logging.channels' => Arr::except(
                (array) config('logging.channels', []),
                self::REMOTE_CHANNELS,
            ),
        ]);
    }
}
