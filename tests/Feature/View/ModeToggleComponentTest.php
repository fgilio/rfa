<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

/** @return array{0: string, 1: string} The review anchor and context anchor blocks. */
function modeToggleAnchors(string $html): array
{
    preg_match_all('/<a\b[^>]*>.*?<\/a>/s', $html, $matches);
    expect($matches[0])->toHaveCount(2);

    return [$matches[0][0], $matches[0][1]];
}

test('marks the review side as current when mode is review', function () {
    $html = Blade::render('<x-mode-toggle mode="review" project-slug="rfa" />');
    [$review, $context] = modeToggleAnchors($html);

    expect($review)->toContain('aria-current="page"');
    expect($context)->not->toContain('aria-current="page"');
});

test('marks the context side as current when mode is context', function () {
    $html = Blade::render('<x-mode-toggle mode="context" project-slug="rfa" />');
    [$review, $context] = modeToggleAnchors($html);

    expect($context)->toContain('aria-current="page"');
    expect($review)->not->toContain('aria-current="page"');
});

test('renders the amber dot on the context side when context activity exists and mode is review', function () {
    $html = Blade::render('<x-mode-toggle mode="review" project-slug="rfa" :has-context-activity="true" />');
    [$review, $context] = modeToggleAnchors($html);

    expect($context)->toContain('bg-amber-500')->toContain('animate-ping');
    expect($review)->not->toContain('bg-amber-500');
});

test('renders the amber dot on the review side when review activity exists and mode is context', function () {
    $html = Blade::render('<x-mode-toggle mode="context" project-slug="rfa" :has-review-activity="true" />');
    [$review, $context] = modeToggleAnchors($html);

    expect($review)->toContain('bg-amber-500')->toContain('animate-ping');
    expect($context)->not->toContain('bg-amber-500');
});

test('never renders the dot on the active side regardless of the activity flag', function () {
    $reviewActiveHtml = Blade::render('<x-mode-toggle mode="review" project-slug="rfa" :has-review-activity="true" :has-context-activity="false" />');
    $contextActiveHtml = Blade::render('<x-mode-toggle mode="context" project-slug="rfa" :has-review-activity="false" :has-context-activity="true" />');

    expect($reviewActiveHtml)->not->toContain('bg-amber-500');
    expect($contextActiveHtml)->not->toContain('bg-amber-500');
});

test('href attributes resolve to the page routes for both sides', function () {
    $html = Blade::render('<x-mode-toggle mode="review" project-slug="my-repo" />');

    expect($html)
        ->toContain('href="'.route('review-page', ['slug' => 'my-repo']).'"')
        ->toContain('href="'.route('context-page', ['slug' => 'my-repo']).'"');
});
