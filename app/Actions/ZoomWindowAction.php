<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Native\Desktop\Facades\Window;

/**
 * Persisted webContents zoom adjustment.
 *
 * Electron 38's role-based zoom accelerators (`zoomIn`/`zoomOut`/`resetZoom`)
 * register on macOS but the keystroke fails to fire `webContentsMethod`,
 * leaving ⌘- silently dead. This action is the single point of truth for
 * zoom adjustments — both the View-menu click handler and the renderer
 * keymap route through it, so the menu accelerator system is bypassed
 * entirely.
 */
final readonly class ZoomWindowAction
{
    public const CACHE_KEY = 'rfa-window-zoom-factor';

    public const MIN = 0.5;

    public const MAX = 3.0;

    public const STEP = 0.1;

    public const DEFAULT = 1.0;

    public function handle(string $direction): float
    {
        $next = match ($direction) {
            'in' => $this->current() + self::STEP,
            'out' => $this->current() - self::STEP,
            'reset' => self::DEFAULT,
            default => throw new InvalidArgumentException("Unknown zoom direction: {$direction}"),
        };

        $clamped = max(self::MIN, min(self::MAX, round($next, 2)));

        Cache::put(self::CACHE_KEY, $clamped, now()->addDays(30));
        Window::get('main')->zoomFactor($clamped);

        return $clamped;
    }

    public function current(): float
    {
        return (float) Cache::get(self::CACHE_KEY, self::DEFAULT);
    }
}
