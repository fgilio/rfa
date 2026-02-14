<?php

namespace App\Livewire;

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\GetFileListAction;
use App\Actions\LoadFileDiffAction;
use App\Actions\ResolveRepoPathAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use App\DTOs\Comment;
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

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files);
        $this->comments = $session['comments'];
        $this->viewedFiles = $session['viewedFiles'];
        $this->globalComment = $session['globalComment'];
    }

    #[On('add-comment')]
    public function addComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body): void
    {
        $comment = app(AddCommentAction::class)->handle($this->files, $fileId, $side, $startLine, $endLine, $body);

        if (! $comment) {
            return;
        }

        $this->comments[] = $comment;
        $this->saveSession();
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        $result = app(DeleteCommentAction::class)->handle($this->comments, $commentId);

        if ($result === null) {
            return;
        }

        $this->comments = $result;
        $this->saveSession();
    }

    #[On('toggle-viewed')]
    public function toggleViewed(string $filePath): void
    {
        $knownPaths = collect($this->files)->pluck('path')->all();
        $result = app(ToggleViewedAction::class)->handle($this->viewedFiles, $filePath, $knownPaths);

        if ($result === null) {
            return;
        }

        $this->viewedFiles = $result;
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

    private function saveSession(): void
    {
        app(SaveSessionAction::class)->handle($this->repoPath, $this->comments, $this->viewedFiles, $this->globalComment);
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
