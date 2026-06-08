<?php

use App\DTOs\FileSourceSpec;
use App\Enums\GitRef;

test('none serializes without a ref or path', function () {
    $source = FileSourceSpec::none();

    expect($source->isNone())->toBeTrue()
        ->and($source->toArray())->toBe([
            'type' => FileSourceSpec::TYPE_NONE,
            'ref' => null,
            'path' => null,
            'absolutePath' => null,
        ]);
});

test('git source serializes a ref and repo relative path', function () {
    $source = FileSourceSpec::git('HEAD', 'app/Foo.php');

    expect($source->toArray())->toBe([
        'type' => FileSourceSpec::TYPE_GIT,
        'ref' => 'HEAD',
        'path' => 'app/Foo.php',
        'absolutePath' => null,
    ]);
});

test('working and index factories use sentinel refs', function () {
    expect(FileSourceSpec::working('app/Foo.php')->toArray())
        ->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => GitRef::Working->value, 'path' => 'app/Foo.php'])
        ->and(FileSourceSpec::index('app/Foo.php')->toArray())
        ->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => GitRef::Index->value, 'path' => 'app/Foo.php']);
});

test('absolute source serializes an on disk path', function () {
    $source = FileSourceSpec::absolute('/tmp/spec.md');

    expect($source->toArray())->toBe([
        'type' => FileSourceSpec::TYPE_ABSOLUTE,
        'ref' => null,
        'path' => null,
        'absolutePath' => '/tmp/spec.md',
    ]);
});
