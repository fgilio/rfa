<?php

use App\DTOs\DiffTarget;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

/**
 * branch-explorer.js can't import PHP constants, so it carries its own copy of
 * the empty-tree hash. Guard the two against drift: the "Since the beginning"
 * row navigates with the JS value, but restore and diffing use the PHP one.
 */
test('branch-explorer.js EMPTY_TREE_HASH matches the PHP constant', function () {
    $js = File::get(base_path('public/js/branch-explorer.js'));

    expect($js)->toMatch("/EMPTY_TREE_HASH\s*=\s*'".preg_quote(DiffTarget::EMPTY_TREE_HASH, '/')."'/");
});
