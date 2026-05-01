<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;
use App\Enums\CommentExportKind;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CommentExporter
{
    public function __construct(
        private readonly MarkdownFormatter $markdownFormatter,
    ) {}

    /**
     * @param  Comment[]  $comments
     * @param  array<string, string>  $diffContext
     * @return array{json: string, md: string, clipboard: string}
     */
    public function export(string $repoPath, array $comments, string $globalComment = '', array $diffContext = [], CommentExportKind $kind = CommentExportKind::Review): array
    {
        $hash = Str::random(8);
        $now = date('Ymd_His');
        $basename = "{$now}_comments_{$hash}";
        $rfaDir = $repoPath.'/.rfa';

        $disk = Storage::build([
            'driver' => 'local',
            'root' => $rfaDir,
            'throw' => true,
        ]);

        $jsonData = [
            'schema_version' => 1,
            'kind' => $kind->value,
            'repo_path' => $repoPath,
            'created_at' => date('c'),
            'markdown_file' => ".rfa/{$basename}.md",
            'global_comment' => $globalComment,
            'comments' => array_map(fn (Comment $c) => $c->toExportArray(), $comments),
        ];

        $md = "<!-- json: .rfa/{$basename}.json -->\n"
            .$this->markdownFormatter->format($comments, $globalComment, $diffContext, $kind);

        $disk->put("{$basename}.json", json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $disk->put("{$basename}.md", $md);

        return [
            'json' => $disk->path("{$basename}.json"),
            'md' => $disk->path("{$basename}.md"),
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
