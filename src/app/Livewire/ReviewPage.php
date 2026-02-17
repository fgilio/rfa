<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveRepoPathAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use Flux;
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
        $this->saveSession();

        $result = app(ExportReviewAction::class)->handle($this->repoPath, $this->comments, $this->globalComment, $this->files);

        $this->exportResult = $result['clipboard'];
        $this->submitted = true;

        Flux::toast(variant: 'success', heading: 'Review submitted', text: $this->exportResult);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard']);
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

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.review-page');
    }
}
