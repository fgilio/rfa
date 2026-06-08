<?php

use App\Actions\GroupReviewFilesAction;
use App\Services\ReviewStateService;

beforeEach(function () {
    $this->action = new GroupReviewFilesAction(new ReviewStateService);
});

test('excludes md review files and keeps source files', function () {
    $files = [
        ['id' => 'file-b', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
        ['id' => 'file-c', 'path' => 'src/Foo.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
    ];

    $result = $this->action->handle($files);

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe('file-c');
});

test('treats stray json files in .rfa/ as source files', function () {
    $files = [
        ['id' => 'file-a', 'path' => '.rfa/20250115_143022_comments_AbCd1234.json', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
        ['id' => 'file-b', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result)->toHaveCount(1)
        ->and($result[0]['id'])->toBe('file-a');
});

test('returns empty when only review files', function () {
    $files = [
        ['id' => 'file-a', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
    ];

    expect($this->action->handle($files))->toBeEmpty();
});

test('returns input untouched when no review files', function () {
    $files = [
        ['id' => 'file-a', 'path' => 'src/Foo.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
        ['id' => 'file-b', 'path' => 'src/Bar.php', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
    ];

    expect($this->action->handle($files))->toHaveCount(2);
});

test('handles empty files array', function () {
    expect($this->action->handle([]))->toBeEmpty();
});

test('preserves source file order', function () {
    $files = [
        ['id' => 'file-a', 'path' => 'src/A.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
        ['id' => 'file-r', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
        ['id' => 'file-b', 'path' => 'src/B.php', 'status' => 'added', 'additions' => 2, 'deletions' => 0],
    ];

    $result = $this->action->handle($files);

    expect($result)->toHaveCount(2)
        ->and($result[0]['id'])->toBe('file-a')
        ->and($result[1]['id'])->toBe('file-b');
});
