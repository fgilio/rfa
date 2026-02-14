<?php

namespace App\Livewire;

use App\DTOs\Comment;
use App\Models\ReviewSession;
use App\Services\CommentExporter;
use App\Services\DiffParser;
use App\Services\GitDiffService;
use Flux;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
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

    /** @var array<int, string> */
    public array $viewedFiles = [];

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
        $this->files = collect($fileDiffs)->map(function ($file) {
            return [
                'id' => 'file-'.md5($file->path),
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

        $this->restoreSession();
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

        $this->saveSession();
    }

    public function deleteComment(string $commentId): void
    {
        $this->comments = array_values(
            array_filter($this->comments, fn ($c) => $c['id'] !== $commentId)
        );

        $this->saveSession();
    }

    #[Renderless]
    public function toggleViewed(string $filePath): void
    {
        if (in_array($filePath, $this->viewedFiles)) {
            $this->viewedFiles = array_values(array_diff($this->viewedFiles, [$filePath]));
        } else {
            $this->viewedFiles[] = $filePath;
        }

        $this->saveSession();
    }

    public function updatedGlobalComment(): void
    {
        $this->saveSession();
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

        Flux::toast(variant: 'success', heading: 'Review submitted', text: $this->exportResult);
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

    /** @return array<int, array<string, mixed>> */
    public function getCommentsForFile(string $fileId): array
    {
        return array_values(
            array_filter($this->comments, fn ($c) => $c['fileId'] === $fileId)
        );
    }

    private function restoreSession(): void
    {
        $session = ReviewSession::firstOrCreate(['repo_path' => $this->repoPath]);

        $currentPaths = collect($this->files)->pluck('path')->all();
        $fileIdMap = collect($this->files)->pluck('id', 'path')->all();

        // Restore viewed files - prune removed files
        /** @var array<int, string> $viewedFiles */
        $viewedFiles = $session->viewed_files ?? [];
        $this->viewedFiles = array_values(array_intersect($viewedFiles, $currentPaths));

        // Restore comments - prune entries for files no longer in the diff, remap fileId
        /** @var array<int, array<string, mixed>> $savedComments */
        $savedComments = $session->comments ?? [];
        $this->comments = collect($savedComments)
            ->filter(fn (array $c) => isset($fileIdMap[$c['file'] ?? '']))
            ->map(fn (array $c) => array_merge($c, ['fileId' => $fileIdMap[$c['file']]]))
            ->values()
            ->all();

        // Restore global comment
        $this->globalComment = $session->global_comment ?? '';
    }

    private function saveSession(): void
    {
        ReviewSession::updateOrCreate(
            ['repo_path' => $this->repoPath],
            [
                'viewed_files' => $this->viewedFiles,
                'comments' => $this->comments,
                'global_comment' => $this->globalComment,
            ]
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.review-page');
    }
}
