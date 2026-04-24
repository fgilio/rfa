<?php

declare(strict_types=1);

namespace App\Support;

use Native\Desktop\Menu\Items\MenuItem;

/**
 * Menu item for Electron's zoom roles (`zoomIn`, `zoomOut`, `resetZoom`),
 * which NativePHP's RolesEnum doesn't expose.
 *
 * Emits `type='normal'` (not `type='role'`) so NativePHP's `compileMenu`
 * helper passes the accelerator through — for role items it strips
 * everything except `role` and `label`, leaving the keyboard binding to
 * Electron's role-default lookup, which on Electron 38 silently fails
 * to register ⌘- for `zoomOut` on macOS (the menu item shows ⌘- and
 * clicks fire, but the keystroke does nothing).
 *
 * Per Electron's docs, when `role` is set on macOS only `label` and
 * `accelerator` affect the item, so the role still drives the zoom
 * action while our explicit accelerator owns the binding.
 *
 * @see https://github.com/electron/electron/issues/19559
 * @see https://github.com/electron/electron/issues/15496
 */
final class ZoomRoleMenuItem extends MenuItem
{
    protected string $type = 'normal';

    public function __construct(
        private string $role,
        ?string $label = null,
        ?string $accelerator = null,
    ) {
        $this->label = $label;
        $this->accelerator = $accelerator ?? self::defaultAcceleratorFor($role);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'role' => $this->role,
        ]);
    }

    private static function defaultAcceleratorFor(string $role): ?string
    {
        return match ($role) {
            'zoomIn' => 'CommandOrControl+Plus',
            'zoomOut' => 'CommandOrControl+-',
            'resetZoom' => 'CommandOrControl+0',
            default => null,
        };
    }
}
