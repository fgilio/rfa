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
            $md .= 'Improve the agent context files below based on the comments. '
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
                    $snippet = $diffContext[$contextKey];
                    $fence = $this->fenceFor($snippet);
                    $md .= "{$lineRef}\n\n";
                    $md .= "{$fence}\n{$snippet}\n{$fence}\n\n";
                } elseif ($lineRef !== '') {
                    $md .= "{$lineRef}\n\n";
                }

                $md .= $this->withClosedFences($comment->body)."\n\n---\n\n";
            }
        }

        return rtrim($md)."\n";
    }

    /**
     * A code fence long enough to wrap $content without the content's own
     * backtick runs closing it early. CommonMark requires the fence to be longer
     * than any run of backticks inside — a snippet that itself contains a ```
     * line (commenting on a markdown file's code block) would otherwise terminate
     * the wrapper and leak the rest of the document.
     */
    private function fenceFor(string $content): string
    {
        preg_match_all('/`+/', $content, $matches);

        $longestRun = 0;
        foreach ($matches[0] as $run) {
            $longestRun = max($longestRun, strlen($run));
        }

        return str_repeat('`', max(3, $longestRun + 1));
    }

    /**
     * Close any code fence the comment body leaves open, so the `---` separator
     * and the following comments aren't swallowed into the block. Bodies with
     * balanced fences are returned unchanged.
     */
    private function withClosedFences(string $body): string
    {
        $openMarker = null;

        foreach (explode("\n", $body) as $line) {
            if (preg_match('/^\s*(`{3,}|~{3,})/', $line, $matches) !== 1) {
                continue;
            }

            $marker = $matches[1][0];
            if ($openMarker === null) {
                $openMarker = $marker;
            } elseif ($marker === $openMarker) {
                $openMarker = null;
            }
        }

        return $openMarker === null ? $body : $body."\n".str_repeat($openMarker, 3);
    }
}
