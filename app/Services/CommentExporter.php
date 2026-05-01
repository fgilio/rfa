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
     * @return array{md: string, clipboard: string}
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

        $md = $this->markdownFormatter->format($comments, $globalComment, $diffContext);

        $disk->put("{$basename}.md", $md);

        return [
            'md' => $disk->path("{$basename}.md"),
            'clipboard' => "address my comments on these changes in @.rfa/{$basename}.md",
        ];
    }
}
