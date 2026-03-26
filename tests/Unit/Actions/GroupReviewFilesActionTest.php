<?php

use App\Actions\GroupReviewFilesAction;
use Faker\Factory as Faker;

beforeEach(function () {
    $this->faker = Faker::create();
    $this->faker->seed(crc32(static::class.$this->name()));
    $this->action = new GroupReviewFilesAction;
});

test('groups json and md files into a review pair', function () {
    $files = [
        ['id' => 'file-a', 'path' => '.rfa/20250115_143022_comments_AbCd1234.json', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
        ['id' => 'file-b', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
        ['id' => 'file-c', 'path' => 'src/Foo.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
    ];

    $result = $this->action->handle($files);

    expect($result['reviewPairs'])->toHaveCount(1)
        ->and($result['reviewPairs'][0]['basename'])->toBe('20250115_143022_comments_AbCd1234')
        ->and($result['reviewPairs'][0]['jsonFile']['id'])->toBe('file-a')
        ->and($result['reviewPairs'][0]['mdFile']['id'])->toBe('file-b')
        ->and($result['sourceFiles'])->toHaveCount(1)
        ->and($result['sourceFiles'][0]['id'])->toBe('file-c');
});

test('sorts review pairs newest first', function () {
    $files = [
        ['id' => 'file-1', 'path' => '.rfa/20250110_100000_comments_aaaa1111.json', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
        ['id' => 'file-2', 'path' => '.rfa/20250110_100000_comments_aaaa1111.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
        ['id' => 'file-3', 'path' => '.rfa/20250115_143022_comments_bbbb2222.json', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
        ['id' => 'file-4', 'path' => '.rfa/20250115_143022_comments_bbbb2222.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result['reviewPairs'])->toHaveCount(2)
        ->and($result['reviewPairs'][0]['basename'])->toBe('20250115_143022_comments_bbbb2222')
        ->and($result['reviewPairs'][1]['basename'])->toBe('20250110_100000_comments_aaaa1111');
});

test('handles orphan json file without md', function () {
    $files = [
        ['id' => 'file-a', 'path' => '.rfa/20250115_143022_comments_AbCd1234.json', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result['reviewPairs'])->toHaveCount(1)
        ->and($result['reviewPairs'][0]['jsonFile'])->not->toBeNull()
        ->and($result['reviewPairs'][0]['mdFile'])->toBeNull();
});

test('handles orphan md file without json', function () {
    $files = [
        ['id' => 'file-a', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result['reviewPairs'])->toHaveCount(1)
        ->and($result['reviewPairs'][0]['jsonFile'])->toBeNull()
        ->and($result['reviewPairs'][0]['mdFile'])->not->toBeNull();
});

test('returns empty reviewPairs when no review files', function () {
    $files = [
        ['id' => 'file-a', 'path' => 'src/Foo.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
        ['id' => 'file-b', 'path' => 'src/Bar.php', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result['reviewPairs'])->toBeEmpty()
        ->and($result['sourceFiles'])->toHaveCount(2);
});

test('handles empty files array', function () {
    $result = $this->action->handle([]);

    expect($result['reviewPairs'])->toBeEmpty()
        ->and($result['sourceFiles'])->toBeEmpty();
});

test('preserves source file order', function () {
    $files = [
        ['id' => 'file-a', 'path' => 'src/A.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
        ['id' => 'file-r', 'path' => '.rfa/20250115_143022_comments_AbCd1234.json', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
        ['id' => 'file-b', 'path' => 'src/B.php', 'status' => 'added', 'additions' => 2, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result['sourceFiles'])->toHaveCount(2)
        ->and($result['sourceFiles'][0]['id'])->toBe('file-a')
        ->and($result['sourceFiles'][1]['id'])->toBe('file-b');
});
