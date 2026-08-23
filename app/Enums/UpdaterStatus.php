<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The states the auto-updater surfaces to the user.
 *
 * `CheckedDev` is development-only: the NativePHP updater never completes a
 * check in a dev build, so a simulated check settles here instead of hanging
 * on `Checking` forever.
 */
enum UpdaterStatus: string
{
    case Checking = 'checking';
    case Downloading = 'downloading';
    case Ready = 'ready';
    case UpToDate = 'up-to-date';
    case CheckedDev = 'checked-dev';
    case Error = 'error';
}
