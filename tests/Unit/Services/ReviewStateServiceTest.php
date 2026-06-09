<?php

use App\DTOs\ReviewState;
use App\Services\ReviewStateService;

beforeEach(function () {
    $this->service = new ReviewStateService;
    $this->files = [
        ['id' => 'file-a', 'path' => 'src/A.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1],
        ['id' => 'file-b', 'path' => 'src/B.php', 'status' => 'added', 'additions' => 10, 'deletions' => 0],
        ['id' => 'file-c', 'path' => 'docs/Guide.md', 'status' => 'deleted', 'additions' => 0, 'deletions' => 4],
        ['id' => 'file-r', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 5, 'deletions' => 0],
    ];
});

test('sourceFiles excludes review artifacts and preserves source order', function () {
    $sourceFiles = $this->service->sourceFiles($this->files);

    expect($sourceFiles)->toHaveCount(3)
        ->and(array_column($sourceFiles, 'id'))->toBe(['file-a', 'file-b', 'file-c']);
});

test('derive builds deterministic sidebar payload and counts', function () {
    $state = $this->service->derive($this->files, ['src/B.php' => 'hash']);

    expect($state->sourceFileEntries)->toBe([
        ['id' => 'file-a', 'path' => 'src/A.php'],
        ['id' => 'file-b', 'path' => 'src/B.php'],
        ['id' => 'file-c', 'path' => 'docs/Guide.md'],
    ])
        ->and($state->reviewedFileIds)->toBe(['file-b'])
        ->and($state->reviewedFileMap)->toBe(['file-b' => true])
        ->and($state->filesById['file-b'])->toBe([
            'path' => 'src/B.php',
            'badgeLabel' => 'A',
            'badgeClass' => 'text-gh-green',
        ])
        ->and($state->countsByStatus)->toBe(['modified' => 1, 'added' => 1, 'deleted' => 1])
        ->and($state->totalFileCount)->toBe(3)
        ->and($state->visibleFileCount)->toBe(3)
        ->and($state->reviewedFileCount)->toBe(1)
        ->and($state->unreviewedFileCount)->toBe(2)
        ->and($state->additions)->toBe(13)
        ->and($state->deletions)->toBe(5);
});

test('selected file remains valid after filtering', function () {
    $state = $this->service->derive($this->files, selectedFileId: 'file-a', fileFilter: 'guide');

    expect($state->visibleFiles)->toHaveCount(1)
        ->and($state->visibleFileEntries)->toBe([['id' => 'file-c', 'path' => 'docs/Guide.md']])
        ->and($state->visibleFileMap)->toBe(['file-c' => true])
        ->and($state->selectedFileId)->toBe('file-c')
        ->and($state->selectedFile['path'])->toBe('docs/Guide.md')
        ->and($state->previousFileId)->toBeNull()
        ->and($state->nextFileId)->toBeNull();
});

test('file navigation neighbors skip hidden reviewed files', function () {
    $state = $this->service->derive(
        $this->files,
        reviewedFiles: ['src/B.php' => 'hash'],
        selectedFileId: 'file-c',
        hideReviewed: true,
    );

    expect(array_column($state->visibleFiles, 'id'))->toBe(['file-a', 'file-c'])
        ->and($state->selectedFileId)->toBe('file-c')
        ->and($state->previousFileId)->toBe('file-a')
        ->and($state->nextFileId)->toBeNull();
});

test('empty changeset returns no files empty state', function () {
    $state = $this->service->derive([]);

    expect($state->emptyStateReason)->toBe(ReviewState::EMPTY_NO_FILES)
        ->and($state->selectedFileId)->toBeNull()
        ->and($state->hasVisibleFiles())->toBeFalse();
});

test('filter with no matches returns filter empty state', function () {
    $state = $this->service->derive($this->files, fileFilter: 'missing');

    expect($state->emptyStateReason)->toBe(ReviewState::EMPTY_FILTER)
        ->and($state->visibleFileCount)->toBe(0);
});

test('hiding reviewed files returns all reviewed empty state when all source files are reviewed', function () {
    $state = $this->service->derive(
        $this->files,
        reviewedFiles: [
            'src/A.php' => 'hash-a',
            'src/B.php' => 'hash-b',
            'docs/Guide.md' => 'hash-c',
        ],
        hideReviewed: true,
    );

    expect($state->emptyStateReason)->toBe(ReviewState::EMPTY_ALL_REVIEWED)
        ->and($state->visibleFileCount)->toBe(0)
        ->and($state->reviewedFileCount)->toBe(3);
});

test('renamed files get the link badge color', function () {
    $state = $this->service->derive([
        ['id' => 'file-r', 'path' => 'src/Renamed.php', 'status' => 'renamed', 'additions' => 1, 'deletions' => 1],
    ]);

    expect($state->filesById['file-r']['badgeLabel'])->toBe('R')
        ->and($state->filesById['file-r']['badgeClass'])->toBe('text-gh-link');
});

test('pathMatchesFilter is trimmed and multibyte case-insensitive', function () {
    expect(ReviewState::pathMatchesFilter('app/Ñandu.php', 'ñandu'))->toBeTrue()
        ->and(ReviewState::pathMatchesFilter('app/Ñandu.php', '  ÑANDU  '))->toBeTrue()
        ->and(ReviewState::pathMatchesFilter('app/Ñandu.php', 'xyz'))->toBeFalse()
        ->and(ReviewState::pathMatchesFilter('app/Foo.php', ''))->toBeTrue();
});

test('pathMatchesFilter agrees with the visible file list', function () {
    $files = [
        ['id' => 'file-n', 'path' => 'app/Ñandu.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
    ];

    $state = $this->service->derive($files, fileFilter: 'ñandu');

    expect($state->visibleFileCount)->toBe(1)
        ->and(ReviewState::pathMatchesFilter('app/Ñandu.php', 'ñandu'))->toBeTrue();
});
