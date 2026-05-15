<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

final readonly class RefreshShortcutPressed
{
    use Dispatchable;

    public const KEY = 'CommandOrControl+R';

    public function __construct(public string $key = self::KEY) {}
}
