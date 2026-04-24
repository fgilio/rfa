<?php

declare(strict_types=1);

namespace App\Support;

final class CsvPath
{
    private const EXTENSIONS = ['csv'];

    public static function isCsv(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
