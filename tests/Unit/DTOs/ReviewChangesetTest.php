<?php

use App\DTOs\DiffTarget;
use App\DTOs\FileListEntry;
use App\DTOs\ReviewChangeset;

test('serializes metadata and existing file entries', function () {
    $target = DiffTarget::rangeToWorking('abc123');
    $entry = new FileListEntry(
        path: 'app/Actions/FooAction.php',
        status: 'modified',
        oldPath: null,
        additions: 4,
        deletions: 2,
        isBinary: false,
        isUntracked: false,
    );

    $changeset = new ReviewChangeset(
        repoPath: '/tmp/repo',
        sourceLabel: $target->contextKey(),
        target: $target,
        files: [$entry],
    );

    expect($changeset->fileCount())->toBe(1)
        ->and($changeset->hasFiles())->toBeTrue()
        ->and($changeset->filesToArray())->toBe([$entry->toArray()])
        ->and($changeset->toArray())->toBe([
            'repoPath' => '/tmp/repo',
            'sourceLabel' => 'abc123..working',
            'target' => ['from' => 'abc123', 'to' => null],
            'files' => [$entry->toArray()],
        ]);
});

test('reports empty changesets', function () {
    $changeset = new ReviewChangeset(
        repoPath: '/tmp/repo',
        sourceLabel: 'HEAD..working',
        target: DiffTarget::workingDirectory(),
        files: [],
    );

    expect($changeset->fileCount())->toBe(0)
        ->and($changeset->hasFiles())->toBeFalse()
        ->and($changeset->filesToArray())->toBe([]);
});
