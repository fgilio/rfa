<?php

declare(strict_types=1);

namespace App\Support;

final class MarkdownPath
{
    private const EXTENSIONS = ['md', 'mdx', 'markdown'];

    public static function isMarkdown(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
