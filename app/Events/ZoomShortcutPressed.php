<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class ZoomShortcutPressed
{
    use Dispatchable;

    public const ZOOM_IN = 'CommandOrControl+=';

    public const ZOOM_IN_PLUS = 'CommandOrControl+Plus';

    public const ZOOM_OUT = 'CommandOrControl+-';

    public const RESET = 'CommandOrControl+0';

    /** @var array<string, 'in'|'out'|'reset'> */
    private const DIRECTIONS_BY_KEY = [
        self::ZOOM_IN => 'in',
        self::ZOOM_IN_PLUS => 'in',
        self::ZOOM_OUT => 'out',
        self::RESET => 'reset',
    ];

    public function __construct(public string $key) {}

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::DIRECTIONS_BY_KEY);
    }

    public function direction(): ?string
    {
        return self::DIRECTIONS_BY_KEY[$this->key] ?? null;
    }
}
