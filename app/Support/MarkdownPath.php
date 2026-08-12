<?php

declare(strict_types=1);

namespace App\Support;

final class MarkdownPath
{
    private const EXTENSIONS = ['md', 'mdc', 'mdx', 'markdown'];

    /** Agent rule files that are markdown despite carrying no extension. */
    private const FILENAMES = ['.cursorrules', '.windsurfrules', '.clinerules'];

    public static function isMarkdown(string $path): bool
    {
        return in_array(basename($path), self::FILENAMES, true)
            || in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::EXTENSIONS, true);
    }
}
