<?php

use App\Actions\DeriveReviewStateAction;
use App\Services\ReviewStateService;

test('handle returns derived review state', function () {
    $action = new DeriveReviewStateAction(new ReviewStateService);

    $state = $action->handle(
        files: [
            ['id' => 'file-a', 'path' => 'src/A.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
            ['id' => 'file-b', 'path' => 'src/B.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
            ['id' => 'file-r', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
        ],
        reviewedFiles: ['src/A.php' => 'hash'],
        selectedFileId: 'file-a',
        fileFilter: 'B.php',
    );

    expect($state->totalFileCount)->toBe(2)
        ->and($state->visibleFileEntries)->toBe([['id' => 'file-b', 'path' => 'src/B.php']])
        ->and($state->selectedFileId)->toBe('file-b')
        ->and($state->reviewedFileIds)->toBe(['file-a'])
        ->and($state->reviewedFileMap)->toBe(['file-a' => true]);
});
