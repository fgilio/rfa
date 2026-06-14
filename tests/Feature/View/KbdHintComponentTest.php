<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

test('renders one keycap per key', function () {
    $html = Blade::render("<x-kbd-hint :keys=\"['j', 'k']\" />");

    expect(substr_count($html, '<kbd'))->toBe(2);
    expect($html)
        ->toContain('>j</kbd>')
        ->toContain('>k</kbd>');
});

test('coerces a single string key into one keycap', function () {
    $html = Blade::render('<x-kbd-hint keys="/" />');

    expect(substr_count($html, '<kbd'))->toBe(1);
    expect($html)->toContain('>/</kbd>');
});

test('renders the slot after the keycaps', function () {
    $html = Blade::render("<x-kbd-hint :keys=\"['x']\">Toggle</x-kbd-hint>");

    expect($html)
        ->toContain('>x</kbd>')
        ->toContain('Toggle');
});

test('forwards class, title, and aria-label to the root span', function () {
    $html = Blade::render(<<<'BLADE'
        <x-kbd-hint
            :keys="['j', 'k']"
            class="text-gh-muted/70"
            title="j next file · k previous file"
            aria-label="Press j for the next file, k for the previous file"
        />
    BLADE);

    expect($html)
        ->toContain('text-gh-muted/70')
        ->toContain('title="j next file · k previous file"')
        ->toContain('aria-label="Press j for the next file, k for the previous file"');
});

test('keycaps carry the boxed border styling', function () {
    $html = Blade::render("<x-kbd-hint :keys=\"['j']\" />");

    expect($html)
        ->toContain('border-gh-border')
        ->toContain('rounded');
});
