<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

final class LocalAsset
{
    public static function script(string $path): HtmlString
    {
        return new HtmlString('<script src="'.e(self::url($path)).'"></script>');
    }

    public static function url(string $path): string
    {
        $normalizedPath = Str::of($path)
            ->replace('\\', '/')
            ->ltrim('/')
            ->toString();

        $absolutePath = public_path($normalizedPath);
        $version = is_file($absolutePath)
            ? (string) filemtime($absolutePath)
            : app()->version();

        return '/'.$normalizedPath.'?v='.$version;
    }
}
