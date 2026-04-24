<?php

declare(strict_types=1);

namespace App\Support;

use Native\Desktop\Menu\Items\MenuItem;

/**
 * Role-type menu item for Electron's zoom roles (`zoomIn`, `zoomOut`,
 * `resetZoom`). NativePHP's RolesEnum doesn't expose them, but Electron
 * accepts the role strings directly and ties each one to a default
 * accelerator (⌘+, ⌘-, ⌘0).
 *
 * Why we emit `type='normal'` instead of `type='role'`:
 *
 * NativePHP's `compileMenu` helper strips everything except `role` and
 * `label` from role items before handing them to Electron, so any
 * `accelerator` we set is dropped. That leaves the menu binding entirely
 * to Electron's role-default lookup — which on Electron 38 silently
 * fails to register ⌘- for `zoomOut` on macOS (the menu item shows the
 * shortcut and clicks fire, but the keystroke does nothing).
 *
 * Per Electron's docs ("when specifying role on macOS, label and
 * accelerator are the only options that will affect the menu item"),
 * emitting `type='normal'` with `role` + `accelerator` lets the helper
 * pass the accelerator through; Electron honors both — the role still
 * drives the zoom action, and our explicit accelerator owns the binding.
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
