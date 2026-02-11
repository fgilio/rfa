<?php

namespace App\Services;

use App\DTOs\Comment;

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
        $dir = $repoPath.'/rfa';

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $jsonPath = "{$dir}/comments_{$hash}.json";
        $mdPath = "{$dir}/comments_{$hash}.md";

        // Build JSON
        $jsonData = [
            'schema_version' => 1,
            'repo_path' => $repoPath,
            'created_at' => date('c'),
            'global_comment' => $globalComment,
            'comments' => array_map(fn (Comment $c) => $c->toArray(), $comments),
        ];

        // Build Markdown
        $md = $this->buildMarkdown($comments, $globalComment, $diffContext);

        // Atomic writes
        $this->atomicWrite($jsonPath, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->atomicWrite($mdPath, $md);

        $clipboardText = "review my comments on these changes in @rfa/comments_{$hash}.md";

        return [
            'json' => $jsonPath,
            'md' => $mdPath,
            'clipboard' => $clipboardText,
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

    private function atomicWrite(string $path, string $content): void
    {
        $tmp = $path.'.tmp.'.getmypid();
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }
}
