<?php

declare(strict_types=1);

namespace App\Support;

use Native\Desktop\Menu\Items\MenuItem;

/**
 * Role-type menu item for Electron's zoom roles (`zoomIn`, `zoomOut`,
 * `resetZoom`). NativePHP's RolesEnum doesn't expose them, but Electron
 * accepts the role strings directly and binds each one to the standard
 * accelerator (⌘+, ⌘-, ⌘0).
 */
final class ZoomRoleMenuItem extends MenuItem
{
    protected string $type = 'role';

    public function __construct(private string $role, ?string $label = null)
    {
        $this->label = $label;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'role' => $this->role,
        ]);
    }
}
