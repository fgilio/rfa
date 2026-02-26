<?php

use App\DTOs\FileListEntry;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
});

test('getId returns file- prefixed hash of path', function () {
    $path = 'src/'.$this->faker->word().'/'.$this->faker->word().'.php';

    $entry = new FileListEntry($path, 'modified', null, 5, 2, false, false);

    expect($entry->getId())->toBe('file-'.hash('xxh128', $path));
});

test('getId is deterministic', function () {
    $path = $this->faker->word().'.php';
    $entry = new FileListEntry($path, 'added', null, 1, 0, false, true);

    expect($entry->getId())->toBe($entry->getId());
});

test('toArray includes all properties and computed id', function () {
    $path = $this->faker->word().'.php';
    $additions = $this->faker->numberBetween(0, 50);
    $deletions = $this->faker->numberBetween(0, 50);

    $entry = new FileListEntry(
        path: $path,
        status: 'modified',
        oldPath: null,
        additions: $additions,
        deletions: $deletions,
        isBinary: false,
        isUntracked: false,
    );

    expect($entry->toArray())->toBe([
        'id' => 'file-'.hash('xxh128', $path),
        'path' => $path,
        'status' => 'modified',
        'oldPath' => null,
        'additions' => $additions,
        'deletions' => $deletions,
        'isBinary' => false,
        'isUntracked' => false,
        'isImage' => false,
        'lastModified' => null,
    ]);
});

test('toArray includes oldPath for renames', function () {
    $oldPath = $this->faker->word().'.php';
    do {
        $newPath = $this->faker->word().'.php';
    } while ($newPath === $oldPath);

    $entry = new FileListEntry($newPath, 'renamed', $oldPath, 0, 0, false, false);

    expect($entry->toArray()['oldPath'])->toBe($oldPath);
});

// -- isImage tests --

test('isImage returns true for image extensions', function () {
    foreach (['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'avif', 'ico'] as $ext) {
        $entry = new FileListEntry("photo.{$ext}", 'added', null, 0, 0, true, true);

        expect($entry->isImage())->toBeTrue("Expected .{$ext} to be detected as image");
    }
});

test('isImage returns false for non-image binary', function () {
    $entry = new FileListEntry('data.bin', 'added', null, 0, 0, true, true);

    expect($entry->isImage())->toBeFalse();
});

test('isImage is case insensitive', function () {
    $entry = new FileListEntry('photo.PNG', 'added', null, 0, 0, true, true);

    expect($entry->isImage())->toBeTrue();
});

test('toArray includes isImage', function () {
    $entry = new FileListEntry('logo.png', 'added', null, 0, 0, true, true);

    expect($entry->toArray()['isImage'])->toBeTrue();
});
