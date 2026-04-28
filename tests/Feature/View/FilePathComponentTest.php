<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

test('emphasizes the basename and dims the directory', function () {
    $html = Blade::render('<x-file-path path="app/Domains/Metadata/Traits/HasTaxonomies.php" />');

    expect($html)
        ->toContain('app/Domains/Metadata/Traits/')
        ->toContain('HasTaxonomies.php')
        ->toContain('opacity-60')
        ->toContain('font-semibold');
});

test('renders the full path in the title attribute by default', function () {
    $html = Blade::render('<x-file-path path="src/Foo.php" />');

    expect($html)->toContain('title="src/Foo.php"');
});

test('caller can override the title attribute', function () {
    $html = Blade::render('<x-file-path path="src/Foo.php" title="Custom hover text" />');

    expect($html)
        ->toContain('title="Custom hover text"')
        ->not->toContain('title="src/Foo.php"');
});

test('renders a single-token path entirely as basename', function () {
    $html = Blade::render('<x-file-path path="README.md" />');

    expect($html)
        ->toContain('README.md')
        ->toContain('font-semibold');
});

test('renders rename with old path muted on the left', function () {
    $html = Blade::render('<x-file-path path="src/New.php" old-path="src/Old.php" />');

    expect($html)
        ->toContain('src/Old.php')
        ->toContain('src/New.php')
        ->toContain('→')
        ->toContain('opacity-50');
});

test('rename title shows old → new', function () {
    $html = Blade::render('<x-file-path path="src/New.php" old-path="src/Old.php" />');

    expect($html)->toContain('title="src/Old.php → src/New.php"');
});

test('handles empty old-path as a regular path', function () {
    $html = Blade::render('<x-file-path path="src/Foo.php" old-path="" />');

    expect($html)
        ->toContain('src/Foo.php')
        ->not->toContain('→');
});

test('preserves nested directories in the dimmed segment', function () {
    $html = Blade::render('<x-file-path path="a/b/c/d/e.txt" />');

    expect($html)
        ->toContain('a/b/c/d/')
        ->toContain('e.txt');
});

test('forwards extra classes to the outer span', function () {
    $html = Blade::render('<x-file-path path="src/Foo.php" class="text-xs custom-marker" />');

    expect($html)
        ->toContain('text-xs')
        ->toContain('custom-marker')
        ->toContain('font-mono');
});

test('directory span allows shrinking so the basename never gets clipped', function () {
    $html = Blade::render('<x-file-path path="some/long/dir/file.php" />');

    expect($html)
        ->toContain('min-w-0')
        ->toContain('truncate')
        ->toContain('shrink-0');
});
