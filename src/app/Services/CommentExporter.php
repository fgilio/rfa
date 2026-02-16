<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\Comment;
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
    public function export(string $repoPath, array $comments, string $globalComment = '', array $diffContext = []): array
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

        // Build JSON
        $jsonData = [
            'schema_version' => 1,
            'repo_path' => $repoPath,
            'created_at' => date('c'),
            'markdown_file' => ".rfa/{$basename}.md",
            'global_comment' => $globalComment,
            'comments' => array_map(fn (Comment $c) => $c->toArray(), $comments),
        ];

        // Build Markdown
        $md = "<!-- json: .rfa/{$basename}.json -->\n"
            .$this->markdownFormatter->format($comments, $globalComment, $diffContext);

        $disk->put("{$basename}.json", json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $disk->put("{$basename}.md", $md);

        return [
            'json' => $disk->path("{$basename}.json"),
            'md' => $disk->path("{$basename}.md"),
            'clipboard' => "review my comments on these changes in @.rfa/{$basename}.md",
        ];
    }
}
