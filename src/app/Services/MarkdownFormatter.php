<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;

class MarkdownFormatter
{
    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     */
    public function format(array $comments, string $globalComment, array $diffContext): string
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
