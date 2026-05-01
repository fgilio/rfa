<?php

use App\Actions\ClearContextCommentsAction;
use App\Actions\ContextCommentWorkflowAction;
use App\Actions\DiscoverAgentContextFilesAction;
use App\Actions\ExportContextFeedbackAction;
use App\Actions\LoadContextCommentsAction;
use App\Actions\PersistProjectViewAction;
use App\Actions\ResolveProjectAction;
use App\DTOs\AgentContextFile;
use App\Enums\LastViewMode;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

use function Illuminate\Support\defer;

/**
 * Context page: inventory + line-level review of every CLAUDE.md / AGENTS.md
 * in the current repo. Sibling to ⚡review-page; intentionally does not share
 * state, comment writes, or sidebar primitives with it (review-page has known
 * debt around comment writes — see CLAUDE.md).
 *
 * Each context file renders through the existing ⚡diff-file Livewire
 * component as an "untracked, file-vs-/dev/null" one-sided diff. That gives
 * file/line/multi-line/global comments for free, with no schema changes:
 * comments on this page are stamped origin_ref="context-file" so they
 * coexist with review comments on the same (repo_path, file_path) row.
 */
new #[Layout('layouts.app')] class extends Component
{
    public string $repoPath = '';

    public int $projectId = 0;

    public string $projectName = '';

    public string $projectSlug = '';

    public string $projectBranch = '';

    public bool $hasRemote = false;

    /** @var array<int, array<string, mixed>> Files in the shape AgentContextFile::toArray() returns, augmented with diff-file fields. */
    public array $contextFiles = [];

    /** @var array<int, array<string, mixed>> View-state comments (origin_ref = context-file). */
    public array $comments = [];

    public string $globalComment = '';

    public bool $submitted = false;

    public ?string $exportResult = null;

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    public function mount(string $slug): void
    {
        $project = app(ResolveProjectAction::class)->handle($slug, touch: true) ?? abort(404);

        $this->repoPath = $project['path'];
        $this->projectId = $project['id'];
        $this->projectName = $project['name'];
        $this->projectSlug = $project['slug'];
        $this->projectBranch = $project['branch'] ?? '';
        $this->hasRemote = ! empty($project['remote_url']);

        Cache::put('rfa.active-project-id', $this->projectId, now()->addDay());

        $projectId = $this->projectId;
        $repoPath = $this->repoPath;

        // Run after the response is sent: the persisted view is only consumed
        // on the next navigation, so making the user wait for the UPSERT here
        // would be needless mount latency.
        defer(static function () use ($projectId, $repoPath) {
            app(PersistProjectViewAction::class)->handle(
                $projectId,
                $repoPath,
                LastViewMode::Context,
            );
        });

        if (config('nativephp-internal.running')) {
            \Native\Desktop\Facades\Window::get('main')->title("rfa - {$this->projectName} · context");
        }

        $this->refreshContextFiles();
        $this->reloadComments();
    }

    public function refresh(): void
    {
        $this->refreshContextFiles();
        $this->reloadComments();
    }

    private function refreshContextFiles(): void
    {
        $discovered = app(DiscoverAgentContextFilesAction::class)->handle($this->repoPath);

        $this->contextFiles = collect($discovered)
            ->map(fn (AgentContextFile $file) => $this->toDiffFileShape($file))
            ->all();
    }

    /**
     * Map an AgentContextFile to the shape ⚡diff-file expects (matches
     * FileListEntry::toArray()). We render every context file as a
     * brand-new ("untracked") file so the diff is file-vs-/dev/null —
     * line numbers map 1-to-1 to the file's own line numbers, which is
     * exactly what we want for line-level commenting.
     *
     * @return array<string, mixed>
     */
    private function toDiffFileShape(AgentContextFile $file): array
    {
        return [
            'id' => $file->id(),
            'path' => $file->path,
            'absolutePath' => $file->absolutePath,
            'kind' => $file->kind->value,
            'directory' => $file->directory(),
            'isTracked' => $file->isTracked,
            'isSymlink' => $file->isSymlink,
            'symlinkTarget' => $file->symlinkTarget,
            'createdAt' => $file->createdAt?->toIso8601String(),
            'lastEditedAt' => $file->lastEditedAt?->toIso8601String(),
            'lineCount' => $file->lineCount,

            // diff-file expectations:
            'status' => 'added',
            'oldPath' => null,
            'additions' => $file->lineCount ?? 0,
            'deletions' => 0,
            'isBinary' => false,
            'isUntracked' => true,
            'isImage' => false,
            'lastModified' => $file->lastEditedAt?->diffForHumans(short: true),
            'fileSize' => null,
            'isExternal' => false,
            'externalAbsolutePath' => null,
        ];
    }

    private function reloadComments(): void
    {
        $this->comments = app(LoadContextCommentsAction::class)
            ->handle($this->repoPath, $this->projectId ?: null);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return collect($this->comments)
            ->groupBy('fileId')
            ->map(fn ($group) => $group->values()->all())
            ->all();
    }

    /** @return array<string, array{count: int, drafts: int}> */
    #[Computed]
    public function commentSummary(): array
    {
        return collect($this->comments)
            ->groupBy('fileId')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'drafts' => $group->where('isDraft', true)->count(),
            ])
            ->all();
    }

    /** Drives the amber dot on the Review side of the mode-toggle. */
    #[Computed]
    public function hasReviewActivity(): bool
    {
        return \App\Models\Comment::forProjectOrRepo($this->projectId ?: null, $this->repoPath)
            ->fromReview()
            ->unsubmitted()
            ->exists();
    }

    #[On('add-comment')]
    public function addComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet = null): void
    {
        $this->createComment($fileId, $side, $startLine, $endLine, $body, $lineSnippet, isDraft: false);
    }

    #[On('add-draft-comment')]
    public function addDraftComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet = null): void
    {
        $this->createComment($fileId, $side, $startLine, $endLine, $body, $lineSnippet, isDraft: true);
    }

    private function createComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body, ?string $lineSnippet, bool $isDraft): void
    {
        try {
            $comment = app(ContextCommentWorkflowAction::class)->handle(
                $this->repoPath,
                $this->projectId ?: null,
                $this->contextFiles,
                $fileId,
                $side,
                $startLine,
                $endLine,
                $body,
                $isDraft,
                $lineSnippet,
            );
        } catch (\App\Exceptions\ContextCommentRejectedException $e) {
            // Stale or malformed payload (renderer state drifted from
            // the actual file). Log the named reason for diagnostics
            // and treat the action as a no-op so the page never
            // crashes on a bad screen state.
            \Illuminate\Support\Facades\Log::debug('Context comment payload rejected', [
                'reason' => $e->reason->value,
                'fileId' => $fileId,
                'side' => $side,
                'startLine' => $startLine,
                'endLine' => $endLine,
            ]);

            return;
        }

        if (! $comment) {
            return;
        }

        $this->comments[] = $comment;
        $this->dispatchFileComments($fileId);
        $this->dispatchSidebarSummary();
        $this->skipRender();
    }

    #[On('update-comment')]
    public function updateComment(string $commentId, string $body, bool $isDraft = false): void
    {
        $index = collect($this->comments)->search(fn ($c) => $c['id'] === $commentId);
        if ($index === false) {
            return;
        }

        if (! app(ContextCommentWorkflowAction::class)->update(
            $this->repoPath,
            $this->projectId ?: null,
            $commentId,
            $body,
            $isDraft,
        )) {
            return;
        }

        $this->comments[$index]['body'] = $body;
        $this->comments[$index]['isDraft'] = $isDraft;
        $this->dispatchFileComments($this->comments[$index]['fileId']);
        $this->dispatchSidebarSummary();
        $this->skipRender();
    }

    #[On('delete-comment')]
    public function deleteComment(string $commentId): void
    {
        $deleted = collect($this->comments)->firstWhere('id', $commentId);
        $fileId = $deleted['fileId'] ?? null;

        $result = app(ContextCommentWorkflowAction::class)->delete(
            $this->repoPath,
            $this->projectId ?: null,
            $this->comments,
            $commentId,
        );
        if ($result === null) {
            return;
        }

        $this->comments = $result;

        if ($fileId) {
            $this->dispatchFileComments($fileId);
        }
        $this->dispatchSidebarSummary();

        if ($deleted) {
            $this->dispatch('undo-available', type: 'delete', payload: [$deleted], message: 'Comment deleted');
        }

        $this->skipRender();
    }

    public function clearAllComments(): void
    {
        if (empty($this->comments)) {
            return;
        }

        $deleted = $this->comments;
        app(ClearContextCommentsAction::class)->handle(
            $this->repoPath,
            $this->projectId ?: null,
            array_column($deleted, 'id'),
        );

        $this->comments = [];

        collect($deleted)
            ->pluck('fileId')
            ->unique()
            ->each(fn (string $fileId) => $this->dispatchFileComments($fileId));
        $this->dispatchSidebarSummary();

        $count = count($deleted);
        $this->dispatch('undo-available', type: 'clear-all', payload: $deleted,
            message: "Cleared {$count} comment".($count === 1 ? '' : 's'));

        $this->skipRender();
    }

    public function undo(string $type, mixed $payload): void
    {
        match ($type) {
            'delete', 'clear-all' => $this->restoreComments($payload),
            default => null,
        };
    }

    /** @param array<int, array<string, mixed>> $comments */
    public function restoreComments(array $comments): void
    {
        if (empty($comments)) {
            return;
        }

        foreach ($comments as $c) {
            \App\Models\Comment::updateOrCreate(
                ['id' => $c['id']],
                [
                    'project_id' => $this->projectId ?: null,
                    'repo_path' => $this->repoPath,
                    'origin_ref' => \App\Models\Comment::ORIGIN_CONTEXT,
                    'file_path' => $c['file'] ?? '',
                    'side' => $c['side'] ?? 'right',
                    'start_line' => $c['startLine'] ?? null,
                    'end_line' => $c['endLine'] ?? null,
                    'file_content_hash' => $c['fileContentHash'] ?? null,
                    'line_snippet' => $c['lineSnippet'] ?? null,
                    'body' => $c['body'] ?? '',
                    'is_draft' => (bool) ($c['isDraft'] ?? false),
                    'submitted_at' => null,
                ],
            );
        }

        $this->reloadComments();
        $this->dispatchSidebarSummary();
        collect($comments)
            ->pluck('fileId')
            ->unique()
            ->each(fn (string $fileId) => $this->dispatchFileComments($fileId));

        $this->skipRender();
    }

    public function submitFeedback(): void
    {
        if (empty($this->comments) && trim($this->globalComment) === '') {
            return;
        }

        $result = app(ExportContextFeedbackAction::class)
            ->handle($this->repoPath, $this->projectId ?: null, $this->comments, $this->globalComment);

        $this->dispatch('copy-to-clipboard', text: $result['clipboard'], toast: 'Feedback copied');

        $this->exportResult = $result['clipboard'];
        $this->submitted = true;
    }

    public function startNewFeedback(): void
    {
        $this->submitted = false;
        $this->exportResult = null;
        $this->globalComment = '';
        $this->reloadComments();
    }

    public function updatedGlobalComment(): void
    {
        $this->skipRender();
    }

    private function dispatchFileComments(string $fileId): void
    {
        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();
        $this->dispatch('comment-updated', fileId: $fileId, comments: $fileComments);
    }

    /**
     * Push the per-file count/draft summary into the sidebar without re-rendering
     * the page. Pairs with skipRender() on every comment mutation — keeps the
     * sidebar's commentSummary in sync without rehydrating N diff-file children.
     */
    private function dispatchSidebarSummary(): void
    {
        $this->dispatch('context-comment-summary-updated', summary: $this->commentSummary())
            ->to('context-tree');
    }
};

?>

<div
    data-testid="context-page"
    x-data="{
        scrollToContextFile(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).then(() => {
            if ($event.detail.toast) Flux.toast({ text: $event.detail.toast, variant: 'success' });
        }).catch(() => {});
    "
    class="min-h-screen flex flex-col"
>
    <x-page-header>
        <div class="flex items-center gap-3 min-w-0">
            @native
                <livewire:project-picker :current-slug="$projectSlug" :project-name="$projectName" mode="context" />
            @else
                <x-page-title class="truncate">{{ $projectName }}</x-page-title>
            @endnative
            <x-mode-toggle
                mode="context"
                :project-slug="$projectSlug"
                :has-review-activity="$this->hasReviewActivity"
                :has-context-activity="false"
            />
            <x-header-separator />
            <span class="section-label text-gh-muted">Agent context</span>
            <span class="font-mono text-xs text-gh-muted">
                {{ count($contextFiles) }} {{ count($contextFiles) === 1 ? 'file' : 'files' }}
            </span>
        </div>
        <div class="flex items-center gap-2">
            <flux:tooltip content="Re-scan repo">
                <flux:button
                    variant="ghost"
                    size="sm"
                    icon="arrow-path"
                    icon:variant="outline"
                    aria-label="Re-scan"
                    wire:click="refresh"
                />
            </flux:tooltip>
        </div>
    </x-page-header>

    <x-resizable-sidebar-shell class="flex-1" main-class="pb-32">
        <x-slot:sidebar>
            <div class="p-4">
                <livewire:context-tree
                    :context-files="$contextFiles"
                    :comment-summary="$this->commentSummary"
                />
            </div>
        </x-slot:sidebar>

        @if(empty($contextFiles))
            <div class="flex flex-col items-center justify-center py-24 px-8 text-center gap-3">
                <flux:icon icon="document-magnifying-glass" variant="outline" class="size-10 text-gh-muted" />
                <div class="font-display font-bold tracking-brutal text-lg">No context files found</div>
                <p class="text-sm text-gh-muted max-w-md">
                    rfa scans for <code class="font-mono">CLAUDE.md</code> and
                    <code class="font-mono">AGENTS.md</code> across this repo. Drop one in
                    the repo root or any subdirectory and re-scan.
                </p>
            </div>
        @else
            @foreach($contextFiles as $file)
                <div id="{{ $file['id'] }}" class="border-b border-gh-border">
                    <livewire:diff-file
                        :key="$file['id']"
                        :file="$file"
                        :load-delay="(int) (floor($loop->index / 15) * 100)"
                        :file-comments="$this->groupedComments[$file['id']] ?? []"
                        :is-reviewed="false"
                        :repo-path="$repoPath"
                        :project-id="$projectId"
                        :has-remote="$hasRemote"
                        :diff-from="$diffFrom"
                        :diff-to="$diffTo"
                    />
                </div>
            @endforeach
        @endif
    </x-resizable-sidebar-shell>

    @include('livewire.undo-toast')

    <x-feedback-submit-bar
        :submitted="$submitted"
        :export-result="$exportResult"
        submitted-heading="Feedback exported"
        submit-label="Submit feedback"
        submit-action="submitFeedback"
        new-round-label="New feedback round"
        new-round-action="startNewFeedback"
        placeholder="Overall feedback on the agent context (optional)"
    />
</div>
