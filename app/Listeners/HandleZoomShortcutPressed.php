<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\ZoomWindowAction;
use App\Events\ZoomShortcutPressed;

final readonly class HandleZoomShortcutPressed
{
    public function __construct(
        private ZoomWindowAction $zoomWindow,
    ) {}

    public function handle(ZoomShortcutPressed $event): void
    {
        $direction = $event->direction();

        if ($direction === null) {
            return;
        }

        $this->zoomWindow->handle($direction);
    }
}
