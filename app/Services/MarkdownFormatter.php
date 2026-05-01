<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;
use App\Enums\CommentExportKind;

class MarkdownFormatter
{
    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     */
    public function format(array $comments, string $globalComment, array $diffContext, CommentExportKind $kind = CommentExportKind::Review): string
    {
        $isContextFile = $kind === CommentExportKind::ContextFile;

        $title = $isContextFile
            ? '# Agent Context Feedback'
            : '# Code Review Comments';

        $md = "{$title}\n\n";

        if ($isContextFile) {
            $md .= 'Improve the CLAUDE.md / AGENTS.md files below based on the comments. '
                .'Treat each comment as a directive: tighten, clarify, remove stale rules, '
                .'or restructure as the comment instructs. Preserve unmentioned content unless '
                ."a comment makes it redundant.\n\n";
        }

        if ($globalComment !== '') {
            $md .= "## General\n\n{$globalComment}\n\n";
        }

        if (empty($comments)) {
            return $md;
        }

        $byFile = collect($comments)->groupBy(fn (Comment $comment): string => $comment->file);

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

                $contextKey = "{$comment->file}:{$comment->side->value}:{$comment->startLine}:{$comment->endLine}";
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
