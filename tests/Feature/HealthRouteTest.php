<?php

use Tests\TestCase;

uses(TestCase::class);

test('health route returns plain-text 200 ok without starting a session', function () {
    $response = $this->get('/_rfa/health');

    $response->assertOk()
        ->assertSee('ok')
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toBe(config('session.cookie'));
    }
});
