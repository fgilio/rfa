<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Str;
use Native\Desktop\Facades\Shell;

final readonly class OpenExternalUrlAction
{
    public function handle(string $url): bool
    {
        if (! Str::isUrl($url, ['http', 'https'])) {
            return false;
        }

        Shell::openExternal($url);

        return true;
    }
}
