<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CommentExporter
{
    public function __construct(
        private readonly MarkdownFormatter $markdownFormatter,
    ) {}

    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     * @return array{md: string, clipboard: string}
     */
    public function export(string $repoPath, array $comments, string $globalComment = '', array $diffContext = []): array
    {
        $basename = date('Ymd_His').'_comments_'.Str::random(8);
        $rfaDir = $repoPath.'/.rfa';
        $path = "{$rfaDir}/{$basename}.md";

        File::ensureDirectoryExists($rfaDir);

        if (File::put($path, $this->markdownFormatter->format($comments, $globalComment, $diffContext)) === false) {
            throw new RuntimeException("Failed to write review file: {$path}");
        }

        return [
            'md' => $path,
            'clipboard' => "address my comments on these changes in @.rfa/{$basename}.md",
        ];
    }
}
