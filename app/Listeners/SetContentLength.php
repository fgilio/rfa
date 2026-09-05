<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Declares the body length on every buffered response, right before it is
 * sent.
 *
 * PHP's built-in server closes the connection to end a response and adds no
 * Content-Length itself. Without one, Chromium treats the document as still
 * loading until the socket closes, which only happens after Laravel's
 * terminate phase and every deferred callback. With the length declared, the
 * renderer finishes the document as soon as the last byte arrives and the
 * after-response work truly runs after the response.
 *
 * Listens on RequestHandled rather than running as middleware because
 * Livewire and Flux inject their assets into the HTML from that same event,
 * after the middleware stack has returned. App providers boot after package
 * providers, so this listener runs once the content is final.
 */
final class SetContentLength
{
    public function handle(RequestHandled $event): void
    {
        self::declare($event->response);
    }

    public static function declare(Response $response): void
    {
        if (! self::canDeclareLength($response)) {
            return;
        }

        $response->headers->set('Content-Length', (string) strlen((string) $response->getContent()));
    }

    /**
     * Streamed and binary-file responses produce their bytes while sending;
     * a response that already declares a transfer encoding or a length is
     * left as it is.
     */
    public static function canDeclareLength(Response $response): bool
    {
        if ($response instanceof StreamedResponse) {
            return false;
        }

        if ($response->headers->has('Transfer-Encoding') || $response->headers->has('Content-Length')) {
            return false;
        }

        if ($response->isInformational() || $response->isEmpty()) {
            return false;
        }

        return is_string($response->getContent());
    }
}
