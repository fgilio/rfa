<?php

use App\Enums\DiscardOperation;

test('maps a changed file to the operation a discard performs', function (string $status, bool $isUntracked, DiscardOperation $expected) {
    expect(DiscardOperation::forChangedFile($status, $isUntracked))->toBe($expected);
})->with([
    'untracked new file' => ['added', true, DiscardOperation::UntrackedFileDeleted],
    'staged new file' => ['added', false, DiscardOperation::AddedFileRemoved],
    'rename' => ['renamed', false, DiscardOperation::RenameReverted],
    'deletion' => ['deleted', false, DiscardOperation::DeletionReverted],
    'edit' => ['modified', false, DiscardOperation::ModificationReverted],
    'binary edit' => ['binary', false, DiscardOperation::ModificationReverted],
]);

test('only a rename stores a second path', function () {
    $usingOldPath = collect(DiscardOperation::cases())->filter(fn (DiscardOperation $case): bool => $case->usesOldPath())->values();

    expect($usingOldPath->all())->toBe([DiscardOperation::RenameReverted]);
});
