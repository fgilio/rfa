<?php

use App\Actions\DeriveReviewStateAction;
use App\Services\ReviewStateService;

test('handle returns derived review state', function () {
    $action = new DeriveReviewStateAction(new ReviewStateService);

    $state = $action->handle(
        files: [
            ['id' => 'file-a', 'path' => 'src/A.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0],
            ['id' => 'file-r', 'path' => '.rfa/20250115_143022_comments_AbCd1234.md', 'status' => 'added', 'additions' => 1, 'deletions' => 0],
        ],
        reviewedFiles: ['src/A.php' => 'hash'],
    );

    expect($state->totalFileCount)->toBe(1)
        ->and($state->reviewedFileIds)->toBe(['file-a'])
        ->and($state->reviewedFileMap)->toBe(['file-a' => true]);
});
