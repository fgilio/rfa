<?php

use App\DTOs\DiffTarget;
use App\DTOs\FileSourceSpec;
use App\Enums\DiffSide;
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

test('working factory uses the sentinel ref', function () {
    expect(FileSourceSpec::working('app/Foo.php')->toArray())
        ->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => GitRef::Working->value, 'path' => 'app/Foo.php']);
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

// -- forSide --

test('forSide resolves the left side to the from ref', function () {
    $source = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::Left, 'src/Foo.php');

    expect($source->toArray())->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => 'abc', 'path' => 'src/Foo.php']);
});

test('forSide resolves the right side to the to ref', function () {
    $source = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::Right, 'src/Foo.php');

    expect($source->toArray())->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => 'def', 'path' => 'src/Foo.php']);
});

test('forSide follows a rename through the old path on the left side', function () {
    $source = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::Left, 'src/New.php', oldPath: 'src/Old.php');

    expect($source->path)->toBe('src/Old.php');
});

test('forSide ignores the old path on the right side', function () {
    $source = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::Right, 'src/New.php', oldPath: 'src/Old.php');

    expect($source->path)->toBe('src/New.php');
});

test('forSide resolves a working-directory target to the working sentinel on the right side', function () {
    $source = FileSourceSpec::forSide(DiffTarget::workingDirectory(), DiffSide::Right, 'src/Foo.php');

    expect($source->ref)->toBe(GitRef::Working->value);
});

test('forSide treats the file side like the right side', function () {
    $right = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::Right, 'src/Foo.php');
    $file = FileSourceSpec::forSide(DiffTarget::range('abc', 'def'), DiffSide::File, 'src/Foo.php');

    expect($file->toArray())->toBe($right->toArray());
});

// -- pairFor --

test('pairFor maps a modified file to both target refs', function () {
    [$old, $new] = FileSourceSpec::pairFor(DiffTarget::range('abc', 'def'), 'src/Foo.php', 'modified');

    expect($old->toArray())->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => 'abc', 'path' => 'src/Foo.php'])
        ->and($new->toArray())->toMatchArray(['type' => FileSourceSpec::TYPE_GIT, 'ref' => 'def', 'path' => 'src/Foo.php']);
});

test('pairFor follows a rename through the old path on the from side', function () {
    [$old, $new] = FileSourceSpec::pairFor(DiffTarget::range('abc', 'def'), 'src/New.php', 'renamed', oldPath: 'src/Old.php');

    expect($old->path)->toBe('src/Old.php')
        ->and($new->path)->toBe('src/New.php');
});

test('pairFor gives an added file no old side', function () {
    [$old, $new] = FileSourceSpec::pairFor(DiffTarget::range('abc', 'def'), 'src/Foo.php', 'added');

    expect($old->isNone())->toBeTrue()
        ->and($new->ref)->toBe('def');
});

test('pairFor treats an untracked file like an addition', function () {
    [$old, $new] = FileSourceSpec::pairFor(DiffTarget::workingDirectory(), 'src/Foo.php', 'modified', isUntracked: true);

    expect($old->isNone())->toBeTrue()
        ->and($new->ref)->toBe(GitRef::Working->value);
});

test('pairFor gives a deleted file no new side', function () {
    [$old, $new] = FileSourceSpec::pairFor(DiffTarget::range('abc', 'def'), 'src/Foo.php', 'deleted');

    expect($old->ref)->toBe('abc')
        ->and($new->isNone())->toBeTrue();
});

test('pairFor resolves a working-directory target to the working sentinel on the new side', function () {
    [, $new] = FileSourceSpec::pairFor(DiffTarget::workingDirectory(), 'src/Foo.php', 'modified');

    expect($new->ref)->toBe(GitRef::Working->value);
});

test('pairFor points an external file at its absolute path', function () {
    [$old, $new] = FileSourceSpec::pairFor(
        DiffTarget::workingDirectory(),
        'spec.md',
        'modified',
        isExternal: true,
        externalAbsolutePath: '/tmp/spec.md',
    );

    expect($old->isNone())->toBeTrue()
        ->and($new->toArray())->toMatchArray(['type' => FileSourceSpec::TYPE_ABSOLUTE, 'absolutePath' => '/tmp/spec.md']);
});

test('pairFor never resolves an external file with a missing absolute path to its mount path', function () {
    foreach ([null, ''] as $missing) {
        [$old, $new] = FileSourceSpec::pairFor(
            DiffTarget::workingDirectory(),
            'external/notes/spec.md',
            'added',
            isExternal: true,
            externalAbsolutePath: $missing,
        );

        expect($old->isNone())->toBeTrue()
            ->and($new->isNone())->toBeTrue();
    }
});
