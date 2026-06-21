<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Read accessor for the keyboard-shortcut catalog (config/shortcuts.php).
 *
 * Shortcut ids contain dots (e.g. `project-picker.toggle`), so `config()`
 * dot-notation can't reach a single entry — it would read the dot as nesting.
 * This helper looks entries up by their literal id instead, giving Blade, the
 * native menu, and the JS bridge one consistent way to read combos and labels.
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

    /** The match/combo string used by the keymap store (e.g. `⌘K`, `j`). */
    public static function combo(string $id): string
    {
        return (string) self::field($id, 'combo');
    }

    /** The human-facing combo for the cheat sheet (`display` override, else combo). */
    public static function display(string $id): string
    {
        $entry = self::all()[$id] ?? [];

        return (string) ($entry['display'] ?? $entry['combo'] ?? '');
    }

    /** The Electron accelerator for natively-owned shortcuts (menu / globalShortcut). */
    public static function accelerator(string $id): ?string
    {
        $value = self::field($id, 'accelerator');

        return $value === null ? null : (string) $value;
    }

    private static function field(string $id, string $key): mixed
    {
        // Ids contain dots, so index by the literal id, then the field — never a
        // single dotted path, which Arr::get / config would read as nesting.
        return (self::all()[$id] ?? [])[$key] ?? null;
    }
}
