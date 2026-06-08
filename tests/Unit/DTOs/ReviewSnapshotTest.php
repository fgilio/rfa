<?php

use App\DTOs\DiffTarget;
use App\DTOs\ReviewSnapshot;

test('toArray returns versioned snapshot payload', function () {
    $snapshot = new ReviewSnapshot(
        repoPath: '/repo/app',
        sourceLabel: 'app',
        target: DiffTarget::workingDirectory(),
        files: [['id' => 'file-a', 'path' => 'src/A.php']],
        comments: [['id' => 'comment-1', 'fileId' => 'file-a']],
        reviewedFileIds: ['file-a'],
        reviewedFiles: ['src/A.php' => 'hash'],
        globalComment: 'ship it',
        exportedAt: '2026-06-08T15:00:00+00:00',
    );

    expect($snapshot->toArray())->toBe([
        'schemaVersion' => ReviewSnapshot::SCHEMA_VERSION,
        'repoPath' => '/repo/app',
        'sourceLabel' => 'app',
        'target' => ['from' => 'HEAD', 'to' => null],
        'files' => [['id' => 'file-a', 'path' => 'src/A.php']],
        'comments' => [['id' => 'comment-1', 'fileId' => 'file-a']],
        'reviewedFileIds' => ['file-a'],
        'reviewedFiles' => ['src/A.php' => 'hash'],
        'globalComment' => 'ship it',
        'exportedAt' => '2026-06-08T15:00:00+00:00',
    ]);
});
