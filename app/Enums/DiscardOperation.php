<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a discard actually did to the working tree. One case per operation, so
 * a trashed file cannot describe a combination that never happened, and each
 * case maps to exactly one way of undoing it.
 *
 * Symlink state stays a separate field: it describes the content that was
 * saved, not the operation that removed it.
 */
enum DiscardOperation: string
{
    /** An untracked file was deleted from disk. */
    case UntrackedFileDeleted = 'untracked-file-deleted';

    /** A staged new file was removed with `git rm`. */
    case AddedFileRemoved = 'added-file-removed';

    /** A rename was rolled back to HEAD on both paths. */
    case RenameReverted = 'rename-reverted';

    /** A deletion was rolled back, bringing the file back from HEAD. */
    case DeletionReverted = 'deletion-reverted';

    /** An edit to a tracked file was rolled back to HEAD. */
    case ModificationReverted = 'modification-reverted';

    /** The operation a discard of a file in the given list state performs. */
    public static function forChangedFile(string $status, bool $isUntracked): self
    {
        return match (true) {
            $status === 'added' && $isUntracked => self::UntrackedFileDeleted,
            $status === 'added' => self::AddedFileRemoved,
            $status === 'renamed' => self::RenameReverted,
            $status === 'deleted' => self::DeletionReverted,
            // 'binary' is a display distinction in the file list, not a
            // different discard: both roll the tracked file back to HEAD. It is
            // listed so `default` stays a genuine fallback for unknown states.
            $status === 'modified', $status === 'binary' => self::ModificationReverted,
            default => self::ModificationReverted,
        };
    }

    /** Only a rename touches a second path, so only a rename may store one. */
    public function usesOldPath(): bool
    {
        return $this === self::RenameReverted;
    }
}
