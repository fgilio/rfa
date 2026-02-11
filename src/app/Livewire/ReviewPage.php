<?php

namespace App\Livewire;

use App\DTOs\Comment;
use App\Services\CommentExporter;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class ReviewPage extends Component
{
    /** @var array<int, array<string, mixed>> */
    public array $files = [];

    /** @var array<int, array<string, mixed>> */
    public array $comments = [];

    public string $globalComment = '';

    public string $repoPath = '';

    public ?string $exportResult = null;

    public bool $submitted = false;

    public ?string $activeFileId = null;

    public function mount(): void
    {
        $repoPathFile = base_path('.rfa_repo_path');
        $this->repoPath = file_exists($repoPathFile)
            ? trim(file_get_contents($repoPathFile))
            : getcwd();

        $gitDiff = app(GitDiffService::class);
        $parser = app(DiffParser::class);

        $rawDiff = $gitDiff->getDiff($this->repoPath);
        $fileDiffs = $parser->parse($rawDiff);

        // Convert to serializable arrays
        $this->files = collect($fileDiffs)->map(function ($file, $index) {
            return [
                'id' => 'file-'.$index,
                'path' => $file->path,
                'status' => $file->status,
                'oldPath' => $file->oldPath,
                'additions' => $file->additions,
                'deletions' => $file->deletions,
                'isBinary' => $file->isBinary,
                'hunks' => collect($file->hunks)->map(fn ($hunk) => [
                    'header' => $hunk->header,
                    'oldStart' => $hunk->oldStart,
                    'newStart' => $hunk->newStart,
                    'lines' => collect($hunk->lines)->map(fn ($line) => [
                        'type' => $line->type,
                        'content' => $line->content,
                        'oldLineNum' => $line->oldLineNum,
                        'newLineNum' => $line->newLineNum,
                    ])->all(),
                ])->all(),
            ];
        })->all();
    }

    public function addComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body): void
    {
        if (trim($body) === '') {
            return;
        }

        $file = collect($this->files)->firstWhere('id', $fileId);
        $filePath = $file['path'] ?? $fileId;

        $this->comments[] = [
            'id' => 'c-'.uniqid(),
            'fileId' => $fileId,
            'file' => $filePath,
            'side' => $side,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'body' => $body,
        ];
    }

    public function deleteComment(string $commentId): void
    {
        $this->comments = array_values(
            array_filter($this->comments, fn ($c) => $c['id'] !== $commentId)
        );
    }

    public function submitReview(): void
    {
        $exporter = app(CommentExporter::class);

        $commentDTOs = array_map(fn ($c) => new Comment(
            id: $c['id'],
            file: $c['file'],
            side: $c['side'],
            startLine: $c['startLine'],
            endLine: $c['endLine'],
            body: $c['body'],
        ), $this->comments);

        // Build diff context for markdown
        $diffContext = $this->buildDiffContext();

        $result = $exporter->export($this->repoPath, $commentDTOs, $this->globalComment, $diffContext);

        $this->exportResult = $result['clipboard'];
        $this->submitted = true;

        $this->dispatch('copy-to-clipboard', text: $result['clipboard']);
    }

    /** @return array<string, string> */
    private function buildDiffContext(): array
    {
        $context = [];

        foreach ($this->comments as $comment) {
            if ($comment['startLine'] === null) {
                continue;
            }

            $file = collect($this->files)->firstWhere('id', $comment['fileId']);
            if (! $file) {
                continue;
            }

            $lines = [];
            foreach ($file['hunks'] as $hunk) {
                foreach ($hunk['lines'] as $line) {
                    $lineNum = $line['newLineNum'] ?? $line['oldLineNum'];
                    if ($lineNum === null) {
                        continue;
                    }
                    if ($lineNum >= $comment['startLine'] && $lineNum <= ($comment['endLine'] ?? $comment['startLine'])) {
                        $prefix = match ($line['type']) {
                            'add' => '+',
                            'remove' => '-',
                            default => ' ',
                        };
                        $lines[] = $prefix.$line['content'];
                    }
                }
            }

            $key = "{$comment['file']}:{$comment['startLine']}:{$comment['endLine']}";
            $context[$key] = implode("\n", $lines);
        }

        return $context;
    }

    public function getCommentCountProperty(): int
    {
        return count($this->comments);
    }

    /** @return array<int, array<string, mixed>> */
    public function getCommentsForFile(string $fileId): array
    {
        return array_values(
            array_filter($this->comments, fn ($c) => $c['fileId'] === $fileId)
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.review-page');
    }
}
