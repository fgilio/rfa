<?php

declare(strict_types=1);

namespace App\Actions;

use App\DTOs\CommentReply as CommentReplyData;
use App\DTOs\CommentThreadSnapshot;
use App\Models\Comment;
use App\Models\TrashedFile;
use App\Support\PathGuard;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final readonly class RestoreDiscardedFileAction
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function handle(int $trashId, string $repoPath, int $projectId): array
    {
        $trashed = TrashedFile::where('id', $trashId)
            ->where('project_id', $projectId)
            ->active()
            ->firstOrFail();

        PathGuard::assertWithinRepo($repoPath, $trashed->file_path);

        if ($trashed->old_path !== null) {
            PathGuard::assertWithinRepo($repoPath, $trashed->old_path);
        }

        $fullPath = $repoPath.'/'.$trashed->file_path;
        $content = Storage::exists($trashed->blobPath())
            ? Storage::get($trashed->blobPath())
            : null;

        match ($trashed->file_status) {
            'deleted' => File::delete($fullPath),
            'renamed' => $this->restoreRenamed($trashed, $repoPath, $fullPath, $content),
            default => $this->writeContent($trashed, $fullPath, $content),
        };

        $rawComments = $trashed->getAttribute('comments');

        /** @var list<array<string, mixed>> $storedComments */
        $storedComments = is_array($rawComments) ? $rawComments : [];

        $snapshots = collect($storedComments)
            ->map(fn (array $comment): CommentThreadSnapshot => CommentThreadSnapshot::fromArray($comment));

        $commentsById = Comment::query()
            ->forProjectOrRepo($projectId, $repoPath)
            ->whereKey($snapshots->map->commentId()->filter())
            ->with('replies')
            ->get()
            ->keyBy('id');

        $comments = $snapshots
            ->map(function (CommentThreadSnapshot $snapshot) use ($commentsById): array {
                $comment = $snapshot->toCommentArray();

                /** @var Comment|null $currentComment */
                $currentComment = $commentsById->get($snapshot->commentId());

                if ($currentComment === null) {
                    return $comment;
                }

                return [
                    ...$comment,
                    'replies' => collect(CommentReplyData::collect($currentComment->replies->toArray()))
                        ->map(fn (CommentReplyData $reply): array => $reply->toArray())
                        ->all(),
                ];
            })
            ->values()
            ->all();

        // Deleting the record also purges its blob (TrashedFile::deleting).
        $trashed->delete();

        return $comments;
    }

    private function writeContent(TrashedFile $trashed, string $fullPath, ?string $content): void
    {
        if ($content === null) {
            return;
        }

        $dir = dirname($fullPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if ($trashed->is_symlink) {
            if (File::exists($fullPath) || is_link($fullPath)) {
                File::delete($fullPath);
            }
            symlink($content, $fullPath);
        } else {
            // If a symlink has appeared at the target since the discard, remove it
            // first. File::put() follows an existing symlink and would write THROUGH
            // it, overwriting whatever it points at — potentially a file outside the
            // repo. assertWithinRepo above blocks an escaping path; this blocks the
            // write-through at the leaf.
            if (is_link($fullPath)) {
                File::delete($fullPath);
            }
            File::put($fullPath, $content);
        }
    }

    private function restoreRenamed(TrashedFile $trashed, string $repoPath, string $fullPath, ?string $content): void
    {
        $this->writeContent($trashed, $fullPath, $content);

        // Delete the restored old_path (git restore brought it back)
        if ($trashed->old_path) {
            $oldFullPath = $repoPath.'/'.$trashed->old_path;
            if (File::exists($oldFullPath)) {
                File::delete($oldFullPath);
            }
        }
    }
}
