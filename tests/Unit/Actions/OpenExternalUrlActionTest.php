<?php

use App\Actions\OpenExternalUrlAction;
use Native\Desktop\Facades\Shell;
use Tests\TestCase;

uses(TestCase::class);

test('opens http and https urls in the system browser', function (string $url) {
    $shell = Shell::fake();

    expect(app(OpenExternalUrlAction::class)->handle($url))->toBeTrue();

    $shell->assertOpenedExternal($url);
})->with([
    'https' => 'https://redsentry.com/contact?source=rfa#quote',
    'http' => 'http://127.0.0.1:8080/report',
]);

test('rejects malformed and non-web urls', function (string $url) {
    $shell = Shell::fake();

    expect(app(OpenExternalUrlAction::class)->handle($url))->toBeFalse()
        ->and($shell->openExternalCalls)->toBeEmpty();
})->with([
    'javascript' => 'javascript:alert(1)',
    'file' => 'file:///etc/passwd',
    'ftp' => 'ftp://example.com/report',
    'relative' => '/internal/path',
    'missing host' => 'https://',
]);
