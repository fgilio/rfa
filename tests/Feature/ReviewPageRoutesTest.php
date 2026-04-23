<?php

use Tests\TestCase;

uses(TestCase::class);

test('review-page named routes cover the four addressable shapes', function () {
    expect(route('review-page', ['slug' => 'demo']))->toEndWith('/p/demo')
        ->and(route('review-page.commit', ['slug' => 'demo', 'hash' => 'abc1234']))->toEndWith('/p/demo/c/abc1234')
        ->and(route('review-page.range', ['slug' => 'demo', 'from' => 'abc1234', 'to' => 'def5678']))->toEndWith('/p/demo/r/abc1234..def5678')
        ->and(route('review-page.range-to-working', ['slug' => 'demo', 'rangeFromWorking' => 'abc1234^']))->toEndWith('/p/demo/rw/abc1234%5E');
});

test('rw path dispatches to the review-page component', function () {
    $routes = app('router')->getRoutes();
    $match = $routes->getByName('review-page.range-to-working');

    expect($match)->not->toBeNull()
        ->and($match->uri())->toBe('p/{slug}/rw/{rangeFromWorking}');
});
