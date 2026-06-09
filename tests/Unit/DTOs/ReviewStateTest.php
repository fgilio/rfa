<?php

use App\DTOs\ReviewState;

test('toArray returns review state payload', function () {
    $state = new ReviewState(
        sourceFiles: [['id' => 'file-a', 'path' => 'src/A.php']],
        visibleFiles: [['id' => 'file-a', 'path' => 'src/A.php']],
        selectedFile: ['id' => 'file-a', 'path' => 'src/A.php'],
        selectedFileId: 'file-a',
        previousFileId: null,
        nextFileId: null,
        reviewedFileIds: ['file-a'],
        reviewedFileMap: ['file-a' => true],
        sourceFileEntries: [['id' => 'file-a', 'path' => 'src/A.php']],
        visibleFileEntries: [['id' => 'file-a', 'path' => 'src/A.php']],
        visibleFileMap: ['file-a' => true],
        filesById: ['file-a' => ['path' => 'src/A.php', 'badgeLabel' => 'M', 'badgeClass' => 'text-gh-attention']],
        countsByStatus: ['modified' => 1],
        totalFileCount: 1,
        visibleFileCount: 1,
        reviewedFileCount: 1,
        unreviewedFileCount: 0,
        additions: 3,
        deletions: 1,
    );

    expect($state->hasVisibleFiles())->toBeTrue()
        ->and($state->toArray())->toMatchArray([
            'selectedFileId' => 'file-a',
            'previousFileId' => null,
            'nextFileId' => null,
            'reviewedFileIds' => ['file-a'],
            'reviewedFileMap' => ['file-a' => true],
            'visibleFileEntries' => [['id' => 'file-a', 'path' => 'src/A.php']],
            'visibleFileMap' => ['file-a' => true],
            'totalFileCount' => 1,
            'visibleFileCount' => 1,
            'reviewedFileCount' => 1,
            'unreviewedFileCount' => 0,
            'additions' => 3,
            'deletions' => 1,
            'emptyStateReason' => ReviewState::EMPTY_NONE,
        ]);
});

test('hasVisibleFiles is false when no files are visible', function () {
    $state = new ReviewState(
        sourceFiles: [],
        visibleFiles: [],
        selectedFile: null,
        selectedFileId: null,
        previousFileId: null,
        nextFileId: null,
        reviewedFileIds: [],
        reviewedFileMap: [],
        sourceFileEntries: [],
        visibleFileEntries: [],
        visibleFileMap: [],
        filesById: [],
        countsByStatus: [],
        totalFileCount: 0,
        visibleFileCount: 0,
        reviewedFileCount: 0,
        unreviewedFileCount: 0,
        additions: 0,
        deletions: 0,
        emptyStateReason: ReviewState::EMPTY_NO_FILES,
    );

    expect($state->hasVisibleFiles())->toBeFalse()
        ->and($state->toArray()['emptyStateReason'])->toBe(ReviewState::EMPTY_NO_FILES);
});
