<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Events\ZoomShortcutPressed;

final readonly class NativeShortcutRegistry
{
    /**
     * App-level shortcuts owned by Electron's globalShortcut API while the main
     * window is focused. Renderer-local keys stay in Alpine where focus matters.
     *
     * @return list<array{key: string, event: class-string}>
     */
    public static function all(): array
    {
        $shortcuts = [
            ['key' => RefreshShortcutPressed::KEY, 'event' => RefreshShortcutPressed::class],
            ['key' => HardReloadShortcutPressed::KEY, 'event' => HardReloadShortcutPressed::class],
            ...array_map(
                fn (string $key): array => ['key' => $key, 'event' => ZoomShortcutPressed::class],
                ZoomShortcutPressed::keys(),
            ),
        ];

        self::ensureKeysAreUnique($shortcuts);

        return $shortcuts;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * @param  list<array{key: string, event: class-string}>  $shortcuts
     */
    private static function ensureKeysAreUnique(array $shortcuts): void
    {
        $keys = array_column($shortcuts, 'key');

        if (count($keys) === count(array_unique($keys))) {
            return;
        }

        throw new \LogicException('Native shortcut keys must be unique.');
    }
}
