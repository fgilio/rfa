<?php

/**
 * Shared helpers for the NativePHP build-time patch scripts.
 */
function nativePatchWriteAtomic(string $path, string $content): bool
{
    $tempPath = $path.'.tmp';
    if (file_put_contents($tempPath, $content) === false) {
        return false;
    }

    return rename($tempPath, $path);
}

function nativePatchExitWithError(string $message): never
{
    fwrite(STDERR, "  ERROR: {$message}\n");
    exit(1);
}
