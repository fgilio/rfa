<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;

/**
 * Declares the body length on a buffered response before it is sent.
 *
 * PHP's built-in server closes the connection to end a response and adds no
 * Content-Length itself. Without one, Chromium treats the document as still
 * loading until the socket closes, which only happens after Laravel's
 * terminate phase and every deferred callback. With the length declared, the
 * renderer finishes the document as soon as the last byte arrives and the
 * after-response work truly runs after the response.
 */
final class ContentLength
{
    public static function declare(Response $response): void
    {
        if ($response->headers->has('Transfer-Encoding')) {
            return;
        }

        if ($response->headers->has('Content-Length')) {
            return;
        }

        if ($response->isInformational() || $response->isEmpty()) {
            return;
        }

        $content = $response->getContent();

        // Streamed and file responses produce their bytes while sending.
        if (! is_string($content)) {
            return;
        }

        $response->headers->set('Content-Length', (string) strlen($content));
    }
}
