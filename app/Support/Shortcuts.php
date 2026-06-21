<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read accessor for the keyboard-shortcut catalog (config/shortcuts.php).
 *
 * Shortcut ids contain dots (e.g. `project-picker.toggle`), so `config()`
 * dot-notation reads the dot as nesting and can't reach a single entry.
 * This helper indexes entries by their literal id instead, giving Blade,
 * the native menu, and the JS bridge one way to read combos and labels.
 */
final class Shortcuts
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        /** @var array<string, array<string, mixed>> */
        return config('shortcuts.shortcuts', []);
    }

    /** @return list<string> */
    public static function groups(): array
    {
        /** @var list<string> */
        return config('shortcuts.groups', []);
    }

    /** The human-facing combo for the cheat sheet (`display` override, else combo). */
    public static function display(string $id): string
    {
        $entry = self::entry($id);

        return (string) ($entry['display'] ?? $entry['combo'] ?? '');
    }

    /** The Electron accelerator for natively-owned shortcuts (menu / globalShortcut). */
    public static function accelerator(string $id): ?string
    {
        $accelerator = self::entry($id)['accelerator'] ?? null;

        return $accelerator === null ? null : (string) $accelerator;
    }

    /** @return array<string, mixed> */
    private static function entry(string $id): array
    {
        return self::all()[$id] ?? [];
    }
}
