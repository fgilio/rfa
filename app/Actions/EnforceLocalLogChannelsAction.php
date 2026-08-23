<?php

declare(strict_types=1);

namespace App\Actions;

use Monolog\Handler\SyslogUdpHandler;

final readonly class EnforceLocalLogChannelsAction
{
    /** Drivers that always write to an off-box destination. */
    private const REMOTE_DRIVERS = ['slack', 'syslog', 'errorlog'];

    /** Monolog `handler_with` keys that name a network destination. */
    private const REMOTE_HANDLER_KEYS = ['host', 'port', 'url', 'connectionString'];

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
            'logging.channels' => collect((array) config('logging.channels', []))
                ->reject(fn (mixed $channel): bool => self::writesOffBox($channel))
                ->all(),
        ]);
    }

    /**
     * Whether a channel configuration sends log records off the machine.
     *
     * Matching on the driver and handler rather than on channel names catches a
     * remote channel any package or framework release adds to the merged
     * configuration, not just the stock four.
     */
    public static function writesOffBox(mixed $channel): bool
    {
        if (! is_array($channel)) {
            return false;
        }

        $driver = $channel['driver'] ?? null;

        if (in_array($driver, self::REMOTE_DRIVERS, true)) {
            return true;
        }

        if ($driver !== 'monolog') {
            return false;
        }

        if (($channel['handler'] ?? null) === SyslogUdpHandler::class) {
            return true;
        }

        $handlerWith = (array) ($channel['handler_with'] ?? []);

        return collect(self::REMOTE_HANDLER_KEYS)
            ->contains(fn (string $key): bool => array_key_exists($key, $handlerWith));
    }
}
