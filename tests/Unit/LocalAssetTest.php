<?php

use App\Support\LocalAsset;
use Tests\TestCase;

uses(TestCase::class);

test('local asset urls include the public file modification time', function () {
    $mtime = filemtime(public_path('js/diff-file.js'));

    expect(LocalAsset::url('/js/diff-file.js'))->toBe("/js/diff-file.js?v={$mtime}");
});

test('local script renders a versioned script tag', function () {
    $mtime = filemtime(public_path('js/diff-file.js'));

    expect((string) LocalAsset::script('js/diff-file.js'))
        ->toBe('<script src="/js/diff-file.js?v='.$mtime.'"></script>');
});
