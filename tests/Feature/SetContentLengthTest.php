<?php

declare(strict_types=1);

use App\Listeners\SetContentLength;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

test('declares the byte length of a buffered HTML response', function () {
    $response = new Response('<p>héllo</p>');
    SetContentLength::declare($response);

    expect($response->headers->get('Content-Length'))->toBe((string) strlen('<p>héllo</p>'));
});

test('declares the length of JSON responses', function () {
    $response = new JsonResponse(['ok' => true]);
    SetContentLength::declare($response);

    expect($response->headers->get('Content-Length'))->toBe((string) strlen('{"ok":true}'));
});

test('leaves streamed, file, empty, and already-measured responses alone', function (BaseResponse $response) {
    $before = $response->headers->get('Content-Length');

    SetContentLength::declare($response);

    expect($response->headers->get('Content-Length'))->toBe($before);
})->with([
    'streamed' => [fn () => new StreamedResponse(fn () => print 'x')],
    'binary file' => [fn () => new BinaryFileResponse(__FILE__)],
    'no content' => [fn () => new Response('', 204)],
    'chunked' => [fn () => new Response('abc', 200, ['Transfer-Encoding' => 'chunked'])],
    'explicit length' => [fn () => new Response('abc', 200, ['Content-Length' => '99'])],
]);

test('a page response carries the length of its final content, Livewire assets included', function () {
    // Livewire skips asset injection under unit tests unless forced.
    Livewire::forceAssetInjection();

    $response = $this->get('/select-repo');

    $response->assertOk();

    expect($response->getContent())->toContain('data-update-uri=')
        ->and($response->headers->get('Content-Length'))->toBe((string) strlen((string) $response->getContent()));
});

test('the listener runs after Livewire injects its assets', function () {
    $listeners = collect(app('events')->getRawListeners()[RequestHandled::class] ?? [])
        ->map(fn (mixed $listener): string => is_string($listener) ? $listener : 'closure');

    expect($listeners->last())->toStartWith(SetContentLength::class)
        ->and($listeners->filter(fn (string $listener): bool => $listener === 'closure'))->not->toBeEmpty();
});
