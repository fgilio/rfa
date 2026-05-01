<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;
use App\Enums\CommentExportKind;
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
    public function export(string $repoPath, array $comments, string $globalComment = '', array $diffContext = [], CommentExportKind $kind = CommentExportKind::Review): array
    {
        $basename = date('Ymd_His').'_comments_'.Str::random(8);
        $rfaDir = $repoPath.'/.rfa';
        $path = "{$rfaDir}/{$basename}.md";

        File::ensureDirectoryExists($rfaDir);

        if (File::put($path, $this->markdownFormatter->format($comments, $globalComment, $diffContext, $kind)) === false) {
            throw new RuntimeException("Failed to write review file: {$path}");
        }

        return [
            'md' => $path,
            'clipboard' => $this->clipboardText($kind, $basename),
        ];
    }

    private function clipboardText(CommentExportKind $kind, string $basename): string
    {
        return match ($kind) {
            CommentExportKind::ContextFile => "improve the agent context files based on my comments in @.rfa/{$basename}.md",
            CommentExportKind::Review => "address my comments on these changes in @.rfa/{$basename}.md",
        };
    }
}
