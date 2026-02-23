<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use Flux;
use Livewire\Attributes\Computed;
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

    public int $projectId = 0;

    public string $projectName = '';

    public string $projectBranch = '';

    public string $projectSlug = '';

    public ?string $exportResult = null;

    public bool $submitted = false;

    /** @var array<int, string> */
    public array $viewedFiles = [];

    public ?string $activeFileId = null;

    public function mount(string $slug): void
    {
        $project = app(ResolveProjectAction::class)->handle($slug);
        $this->repoPath = $project['path'];
        $this->projectId = $project['id'];
        $this->projectName = $project['name'];
        $this->projectBranch = $project['branch'] ?? '';
        $this->projectSlug = $project['slug'];

        $this->files = app(GetFileListAction::class)->handle($this->repoPath, projectId: $this->projectId);

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files, $this->projectId);
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
        $this->dispatchFileComments($fileId);
        $this->skipRender();
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        $fileId = collect($this->comments)->firstWhere('id', $commentId)['fileId'] ?? null;

        $result = app(DeleteCommentAction::class)->handle($this->comments, $commentId);

        if ($result === null) {
            return;
        }

        $this->comments = $result;
        $this->saveSession();

        if ($fileId) {
            $this->dispatchFileComments($fileId);
        }

        $this->skipRender();
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
        $this->skipRender();
    }

    public function updatedGlobalComment(): void
    {
        $this->saveSession();
        $this->skipRender();
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

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return collect($this->comments)->groupBy('fileId')->map->values()->map->all()->all();
    }

    private function dispatchFileComments(string $fileId): void
    {
        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();
        $this->dispatch('comment-updated', fileId: $fileId, comments: $fileComments);
    }

    private function saveSession(): void
    {
        app(SaveSessionAction::class)->handle($this->repoPath, $this->comments, $this->viewedFiles, $this->globalComment, $this->projectId);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return view('livewire.review-page');
    }
}
