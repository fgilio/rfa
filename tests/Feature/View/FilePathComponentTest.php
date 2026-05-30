<?php

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

uses(TestCase::class);

test('emphasizes the basename and dims the directory', function () {
    $html = Blade::render('<x-file-path path="app/Domains/Metadata/Traits/HasTaxonomies.php" />');

    expect($html)
        ->toContain('app/Domains/Metadata/Traits/')
        ->toContain('HasTaxonomies.php')
        ->toContain('text-gh-muted/70')
        ->toContain('text-gh-text');
});

test('collapse mode middle-ellipsizes a deep directory but keeps the full path in title', function () {
    $html = Blade::render('<x-file-path path="app/Domains/BulkOperations/Jobs/ImportUsersJob.php" :collapse="true" />');

    expect($html)
        ->toContain('app/…/Jobs/')                 // directory collapsed to a breadcrumb
        ->toContain('ImportUsersJob.php')           // basename survives intact
        ->not->toContain('app/Domains/BulkOperations/Jobs/</span>') // full chain not shown inline
        ->toContain('title="app/Domains/BulkOperations/Jobs/ImportUsersJob.php"'); // recoverable on hover
});

test('collapse mode leaves shallow paths untouched', function () {
    $html = Blade::render('<x-file-path path="app/Foo.php" :collapse="true" />');

    expect($html)->toContain('app/')->toContain('Foo.php')->not->toContain('…');
});

test('collapse mode preserves the leading slash on absolute paths', function () {
    $html = Blade::render('<x-file-path path="/Users/me/project/src/Foo.php" :collapse="true" />');

    // The root segment survives — without the leading-slash guard this would
    // collapse to a degenerate '/…/src/' that drops the meaningful top dir.
    expect($html)
        ->toContain('/Users/…/src/')
        ->toContain('Foo.php')
        ->toContain('title="/Users/me/project/src/Foo.php"');
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
        ->toContain('text-gh-text');
});

test('renders rename with old path muted on the left', function () {
    $html = Blade::render('<x-file-path path="src/New.php" old-path="src/Old.php" />');

    expect($html)
        ->toContain('src/Old.php')
        ->toContain('src/New.php')
        ->toContain('→')
        ->toContain('text-gh-muted/50');
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

test('whole line truncates from the right when it overflows', function () {
    $html = Blade::render('<x-file-path path="some/long/dir/file.php" />');

    expect($html)
        ->toContain('min-w-0')
        ->toContain('truncate')
        ->toContain('max-w-full');
});

test('overrides inherited text-align so short paths don\'t drift in centered parents like buttons', function () {
    $html = Blade::render('<x-file-path path="README.md" />');

    expect($html)->toContain('text-left');
});
