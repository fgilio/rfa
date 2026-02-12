<?php

namespace App\Services;

use App\DTOs\Comment;
use Illuminate\Support\Facades\Storage;

class CommentExporter
{
    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     * @return array{json: string, md: string, clipboard: string}
     */
    public function export(string $repoPath, array $comments, string $globalComment = '', array $diffContext = []): array
    {
        $hash = substr(md5(json_encode($comments).$globalComment.time()), 0, 8);
        $rfaDir = $repoPath.'/.rfa';

        $disk = Storage::build([
            'driver' => 'local',
            'root' => $rfaDir,
            'throw' => true,
        ]);

        // Build JSON
        $jsonData = [
            'schema_version' => 1,
            'repo_path' => $repoPath,
            'created_at' => date('c'),
            'markdown_file' => ".rfa/comments_{$hash}.md",
            'global_comment' => $globalComment,
            'comments' => array_map(fn (Comment $c) => $c->toArray(), $comments),
        ];

        // Build Markdown
        $md = "<!-- json: .rfa/comments_{$hash}.json -->\n"
            .$this->buildMarkdown($comments, $globalComment, $diffContext);

        $disk->put("comments_{$hash}.json", json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $disk->put("comments_{$hash}.md", $md);

        return [
            'json' => $disk->path("comments_{$hash}.json"),
            'md' => $disk->path("comments_{$hash}.md"),
            'clipboard' => "review my comments on these changes in @.rfa/comments_{$hash}.md",
        ];
    }

    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     */
    private function buildMarkdown(array $comments, string $globalComment, array $diffContext): string
    {
        $md = "# Code Review Comments\n\n";

        if ($globalComment !== '') {
            $md .= "## General\n\n{$globalComment}\n\n";
        }

        if (empty($comments)) {
            return $md;
        }

        // Group by file
        $byFile = [];
        foreach ($comments as $comment) {
            $byFile[$comment->file][] = $comment;
        }

        foreach ($byFile as $file => $fileComments) {
            $md .= "## `{$file}`\n\n";

            foreach ($fileComments as $comment) {
                $lineRef = '';
                if ($comment->startLine !== null) {
                    $lineRef = $comment->startLine === $comment->endLine || $comment->endLine === null
                        ? "Line {$comment->startLine}"
                        : "Lines {$comment->startLine}-{$comment->endLine}";
                    $lineRef = "**{$lineRef}**";
                }

                // Include diff context snippet if available
                $contextKey = "{$comment->file}:{$comment->startLine}:{$comment->endLine}";
                if (isset($diffContext[$contextKey]) && $diffContext[$contextKey] !== '') {
                    $md .= "{$lineRef}\n\n";
                    $md .= "```\n{$diffContext[$contextKey]}\n```\n\n";
                } elseif ($lineRef !== '') {
                    $md .= "{$lineRef}\n\n";
                }

                $md .= "{$comment->body}\n\n---\n\n";
            }
        }

        return rtrim($md)."\n";
    }
}
