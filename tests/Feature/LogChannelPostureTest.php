<?php

use Monolog\Handler\SyslogUdpHandler;
use Tests\TestCase;

uses(TestCase::class);

/**
 * Wide-events Storage rule C8 (see .claude/skills/wide-events/SKILL.md): the
 * resolved default log channel — and, when it is a stack, every channel in it —
 * must stay local. RFA ships to user machines and never sends logs off-box.
 *
 * This needs the resolved Laravel config (env + stack expansion), so it lives
 * here rather than in tests/Arch, which runs without app context.
 */

/** Drivers that always write to an off-box destination. */
function remoteLogDrivers(): array
{
    return ['slack', 'syslog', 'errorlog'];
}

/** Monolog `handler_with` keys that point at a network destination. */
function remoteHandlerKeys(): array
{
    return ['host', 'port', 'url', 'connectionString'];
}

/**
 * Channel names reachable from `$channel`, expanding any `stack` driver
 * recursively. Cycles are guarded by `$seen`.
 *
 * @param  list<string>  $seen
 * @return list<string>
 */
function resolvedLogChannels(string $channel, array $seen = []): array
{
    if (in_array($channel, $seen, true)) {
        return [];
    }

    $seen[] = $channel;
    $config = config("logging.channels.{$channel}");

    if (! is_array($config)) {
        return [$channel];
    }

    if (($config['driver'] ?? null) !== 'stack') {
        return [$channel];
    }

    $resolved = [];

    foreach ($config['channels'] ?? [] as $member) {
        $resolved = [...$resolved, ...resolvedLogChannels((string) $member, $seen)];
    }

    return $resolved;
}

function isRemoteLogChannel(string $channel): bool
{
    $config = config("logging.channels.{$channel}");

    if (! is_array($config)) {
        return false;
    }

    $driver = $config['driver'] ?? null;

    if (in_array($driver, remoteLogDrivers(), true)) {
        return true;
    }

    if ($driver !== 'monolog') {
        return false;
    }

    if (($config['handler'] ?? null) === SyslogUdpHandler::class) {
        return true;
    }

    $handlerWith = $config['handler_with'] ?? [];

    return collect(remoteHandlerKeys())->contains(fn (string $key): bool => array_key_exists($key, $handlerWith));
}

test('the default log channel resolves to local-only sinks', function () {
    $default = config('logging.default');

    expect($default)->toBeString();

    $channels = resolvedLogChannels((string) $default);

    expect($channels)->not->toBeEmpty();

    $remote = collect($channels)->filter(fn (string $channel): bool => isRemoteLogChannel($channel))->values()->all();

    expect($remote)->toBeEmpty();
});
