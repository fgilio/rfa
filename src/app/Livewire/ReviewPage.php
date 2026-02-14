<?php

namespace App\Livewire;

use App\Actions\GetFileListAction;
use App\Actions\LoadFileDiffAction;
use App\Actions\ResolveRepoPathAction;
use App\DTOs\Comment;
use App\Models\ReviewSession;
use App\Services\CommentExporter;
use Flux;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
        $this->repoPath = app(ResolveRepoPathAction::class)->handle();
        $this->files = app(GetFileListAction::class)->handle($this->repoPath);
        $this->restoreSession();
    }

    #[On('add-comment')]
    public function addComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body): void
    {
        if (trim($body) === '') {
            return;
        }

        $file = collect($this->files)->firstWhere('id', $fileId);
        if (! $file || ! in_array($side, ['left', 'right', 'file'])) {
            return;
        }

        $this->comments[] = [
            'id' => 'c-'.uniqid(),
            'fileId' => $fileId,
            'file' => $file['path'],
            'side' => $side,
            'startLine' => $startLine,
            'endLine' => $endLine,
            'body' => $body,
        ];

        $this->saveSession();
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        if (! str_starts_with($commentId, 'c-')) {
            return;
        }

        $this->comments = array_values(
            array_filter($this->comments, fn ($c) => $c['id'] !== $commentId)
        );

        $this->saveSession();
    }

    #[On('toggle-viewed')]
    public function toggleViewed(string $filePath): void
    {
        $knownPaths = collect($this->files)->pluck('path')->all();
        if (! in_array($filePath, $knownPaths)) {
            return;
        }

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
        $loaded = [];

        foreach ($this->comments as $comment) {
            if ($comment['startLine'] === null) {
                continue;
            }

            $file = collect($this->files)->firstWhere('id', $comment['fileId']);
            if (! $file) {
                continue;
            }

            $fileId = $file['id'];

            if (! isset($loaded[$fileId])) {
                $cacheKey = 'rfa_diff_'.md5($this->repoPath.':'.$fileId);
                $loaded[$fileId] = Cache::get($cacheKey) ?? $this->loadDiffDataForFile($file);
            }

            $diffData = $loaded[$fileId];
            if (! $diffData) {
                continue;
            }

            $useOld = $comment['side'] === 'left';
            $lines = [];
            foreach ($diffData['hunks'] as $hunk) {
                foreach ($hunk['lines'] as $line) {
                    $lineNum = $useOld
                        ? ($line['oldLineNum'] ?? $line['newLineNum'])
                        : ($line['newLineNum'] ?? $line['oldLineNum']);
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
    public function getFileComments(string $fileId): array
    {
        return $this->groupedComments()[$fileId] ?? [];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function groupedComments(): array
    {
        return collect($this->comments)->groupBy('fileId')->map->values()->map->all()->all();
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

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>|null
     */
    private function loadDiffDataForFile(array $file): ?array
    {
        return app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $file['path'],
            $file['isUntracked'] ?? false,
        );
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.review-page');
    }
}
