<?php

use App\Actions\ClearContextCommentsAction;
use App\Actions\ContextCommentWorkflowAction;
use App\Actions\DiscoverAgentContextFilesAction;
use App\Actions\ExportContextFeedbackAction;
use App\Actions\LoadContextCommentsAction;
use App\Actions\ResolveProjectAction;
use App\DTOs\AgentContextFile;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

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

        if (! app(ContextCommentWorkflowAction::class)->update($commentId, $body, $isDraft)) {
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

        $result = app(ContextCommentWorkflowAction::class)->delete($this->comments, $commentId);
        if ($result === null) {
            return;
        }

        $this->comments = $result;

        if ($fileId) {
            $this->dispatchFileComments($fileId);
        }
        $this->dispatchSidebarSummary();
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
    class="min-h-screen flex flex-col"
>
    <div
        class="sticky top-0 z-50"
        x-data
        x-init="
            const update = () => document.documentElement.style.setProperty('--header-h', $el.offsetHeight + 'px');
            update();
            new ResizeObserver(update).observe($el);
        "
    >
        <header class="bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-5 py-3.5 flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0">
                @native
                    <livewire:project-picker :current-slug="$projectSlug" :project-name="$projectName" mode="context" />
                @else
                    <span class="font-display font-bold tracking-brutal-tight text-base truncate">{{ $projectName }}</span>
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
        </header>
    </div>

    <div class="flex flex-1">
        {{-- Sidebar --}}
        <aside class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-bg hidden lg:block w-72">
            <div class="p-4">
                <livewire:context-tree
                    :context-files="$contextFiles"
                    :comment-summary="$this->commentSummary"
                />
            </div>
        </aside>

        {{-- Main column --}}
        <main class="flex-1 min-w-0 pb-32">
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
        </main>
    </div>

    {{-- Submit bar --}}
    <div class="fixed bottom-0 left-0 right-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-t border-gh-border">
        @if($submitted)
            <div class="px-5 py-3.5 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <flux:icon icon="check-circle" variant="outline" class="text-gh-green shrink-0" />
                    <span class="font-semibold tracking-brutal shrink-0">Feedback exported</span>
                    <span class="font-mono text-xs text-gh-muted px-2 py-0.5 rounded border border-gh-border truncate">{{ $exportResult }}</span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <flux:button
                        size="sm"
                        icon="clipboard-document"
                        icon:variant="outline"
                        @click="$dispatch('copy-to-clipboard', { text: @js($exportResult), toast: 'Copied again' })"
                    >
                        Copy again
                    </flux:button>
                    <flux:button
                        variant="primary"
                        size="sm"
                        icon="pencil-square"
                        icon:variant="outline"
                        wire:click="startNewFeedback"
                    >
                        New feedback round
                    </flux:button>
                </div>
            </div>
        @else
            <div class="px-5 py-3.5 flex items-center gap-4"
                x-data="{
                    get commentCount() { return $wire.comments.filter(c => !c.isDraft).length },
                    get draftCount() { return $wire.comments.filter(c => c.isDraft).length },
                    get hasGlobal() { return ($wire.globalComment || '').trim().length > 0 }
                }"
            >
                <div class="flex-1">
                    <flux:textarea
                        wire:model.live.debounce.500ms="globalComment"
                        placeholder="Overall feedback on the agent context (optional)"
                        rows="auto"
                        resize="none"
                        class="font-mono text-xs"
                    />
                </div>
                <div class="flex items-center gap-3 shrink-0">
                    <template x-if="commentCount > 0">
                        <span class="font-mono text-xs text-gh-muted" x-text="commentCount + ' ' + (commentCount === 1 ? 'comment' : 'comments')"></span>
                    </template>
                    <template x-if="draftCount > 0">
                        <span class="font-mono text-xs text-gh-draft" x-text="draftCount + ' ' + (draftCount === 1 ? 'draft' : 'drafts')"></span>
                    </template>
                    <template x-if="commentCount + draftCount > 0">
                        <div class="flex items-center gap-3">
                            <x-arm-commit-button
                                icon="trash"
                                tooltip="Clear all comments"
                                @confirmed="$wire.clearAllComments()"
                            />
                            <span class="w-px h-4 bg-gh-border"></span>
                        </div>
                    </template>
                    <flux:button
                        variant="primary"
                        @click="if (draftCount > 0 && !confirm(`You have ${draftCount} draft comment${draftCount === 1 ? '' : 's'} that won't be included. Submit anyway?`)) return; $wire.submitFeedback()"
                        x-bind:disabled="commentCount === 0 && !hasGlobal"
                    >
                        Submit feedback
                    </flux:button>
                </div>
            </div>
        @endif
    </div>
</div>
