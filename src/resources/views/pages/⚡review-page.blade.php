<?php

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use App\Exceptions\GitCommandException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
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

    public ?string $gitError = null;

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

        try {
            $this->files = app(GetFileListAction::class)->handle($this->repoPath, projectId: $this->projectId);
        } catch (GitCommandException $e) {
            $this->gitError = $e->stderr ?: $e->getMessage();
            $this->files = [];
        }

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
        return collect($this->comments)->groupBy('fileId')->map(fn ($group) => $group->values()->all())->all();
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
};
?>

<div
    data-testid="review-component"
    x-data="{
        activeFile: null,
        viewedFiles: {{ Js::from((object) collect($files)->filter(fn($f) => in_array($f['path'], $viewedFiles))->pluck('id')->flip()->map(fn() => true)->all()) }},
        fileFilter: '',
        filePaths: {{ Js::from(collect($files)->pluck('path')->all()) }},
        sidebarWidth: parseInt(localStorage.getItem('rfa-sidebar-width') || 288),
        resizing: false,
        fileMatchesFilter(path) {
            return this.fileFilter === '' || path.toLowerCase().includes(this.fileFilter.toLowerCase());
        },
        scrollToFile(id) {
            this.activeFile = id;
            this.$dispatch('expand-file', { id });
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        startResize(e) {
            this.resizing = true;
            const startX = e.clientX;
            const startWidth = this.sidebarWidth;
            const aside = this.$refs.sidebar;
            const main = aside.parentElement.querySelector('main');
            let raf = null;
            let currentWidth = startWidth;

            // Float sidebar above main so diff DOM never reflows during drag
            aside.style.position = 'fixed';
            aside.style.left = '0';
            aside.style.zIndex = '40';
            aside.style.willChange = 'width';
            main.style.marginLeft = startWidth + 'px';
            document.body.classList.add('cursor-col-resize', 'select-none');

            const onMove = (e) => {
                currentWidth = Math.min(600, Math.max(200, startWidth + e.clientX - startX));
                if (raf) return;
                raf = requestAnimationFrame(() => {
                    aside.style.width = currentWidth + 'px';
                    raf = null;
                });
            };
            const onUp = () => {
                if (raf) { cancelAnimationFrame(raf); raf = null; }
                aside.style.position = '';
                aside.style.left = '';
                aside.style.zIndex = '';
                aside.style.willChange = '';
                main.style.marginLeft = '';
                this.resizing = false;
                this.sidebarWidth = currentWidth;
                document.body.classList.remove('cursor-col-resize', 'select-none');
                localStorage.setItem('rfa-sidebar-width', currentWidth);
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
    }"
    @file-viewed-changed.window="viewedFiles[$event.detail.id] = $event.detail.viewed"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).catch(() => {});
    "
    @keydown.window="
        if ($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT') {
            if ($event.key === 'Escape') { fileFilter = ''; $event.target.blur(); $event.preventDefault(); }
            return;
        }
        if ($event.key === '/') { $refs.fileFilterInput?.focus(); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'C') { $dispatch('collapse-all-files'); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'E') { $dispatch('expand-all-files'); $event.preventDefault(); }
    "
>
    {{-- Header --}}
    <header class="sticky top-0 z-50 bg-gh-surface border-b border-gh-border px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <a href="/" class="hover:opacity-80 transition-opacity"><flux:heading size="lg">rfa</flux:heading></a>
            <flux:text variant="subtle" size="sm">{{ $projectName }}</flux:text>
            @if($projectBranch)
                <flux:badge size="sm" variant="outline">{{ $projectBranch }}</flux:badge>
            @endif
        </div>
        <div class="flex items-center gap-3 text-xs">
            <flux:text variant="subtle" size="sm" inline
                x-text="fileFilter === ''
                    ? '{{ count($files) }} {{ Str::plural('file', count($files)) }}'
                    : filePaths.filter(p => fileMatchesFilter(p)).length + '/{{ count($files) }} files'"
            >{{ count($files) }} {{ Str::plural('file', count($files)) }}</flux:text>
            <flux:text variant="subtle" size="sm" inline
                x-show="Object.values(viewedFiles).filter(Boolean).length > 0"
                x-text="Object.values(viewedFiles).filter(Boolean).length + '/{{ count($files) }} viewed'"
                x-cloak />
            <flux:badge color="green" size="sm">+{{ collect($files)->sum('additions') }}</flux:badge>
            <flux:badge color="red" size="sm">-{{ collect($files)->sum('deletions') }}</flux:badge>
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:tooltip content="Collapse all (Shift+C)">
                <flux:button variant="ghost" size="sm" icon="bars-arrow-up"
                    @click="$dispatch('collapse-all-files')" />
            </flux:tooltip>
            <flux:tooltip content="Expand all (Shift+E)">
                <flux:button variant="ghost" size="sm" icon="bars-arrow-down"
                    @click="$dispatch('expand-all-files')" />
            </flux:tooltip>
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="moon" x-show="! $flux.dark" x-cloak />
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="sun" x-show="$flux.dark" />
        </div>
    </header>

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-surface hidden lg:block relative" :style="{ width: sidebarWidth + 'px' }" x-ref="sidebar">
            <div class="p-3">
                <flux:heading class="!text-xs uppercase tracking-wide mb-2">Files</flux:heading>
                <flux:input
                    x-model.debounce.150ms="fileFilter"
                    placeholder="Filter files..."
                    icon="magnifying-glass"
                    clearable
                    kbd="/"
                    size="sm"
                    variant="filled"
                    class="mb-2"
                    x-ref="fileFilterInput"
                    @keydown.escape="fileFilter = ''; $el.blur()"
                />
                @foreach($files as $file)
                    @php
                        [$badgeColor, $badgeLabel] = match($file['status']) {
                            'added' => ['green', 'A'],
                            'deleted' => ['red', 'D'],
                            'renamed' => ['yellow', 'R'],
                            default => ['yellow', 'M'],
                        };
                    @endphp
                    <button
                        x-show="fileMatchesFilter({{ Js::from($file['path']) }})"
                        @click="scrollToFile('{{ $file['id'] }}')"
                        class="w-full text-left px-2 py-1.5 rounded text-xs hover:bg-gh-border/50 flex items-center gap-2 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-border/50 text-gh-accent' : 'text-gh-text'"
                    >
                        <flux:badge variant="solid" :color="$badgeColor" size="sm" class="!text-[10px] !px-1 !py-0 w-4 shrink-0">{{ $badgeLabel }}</flux:badge>
                        <span class="truncate" title="{{ $file['path'] }}{{ ($file['lastModified'] ?? null) ? "\nModified " . $file['lastModified'] : '' }}">{{ $file['path'] }}</span>
                        <flux:icon icon="check" variant="micro" x-show="viewedFiles['{{ $file['id'] }}']"
                            class="text-gh-green shrink-0" x-cloak />
                        <span class="ml-auto flex gap-1 shrink-0">
                            @if($file['additions'] > 0)
                                <flux:badge color="green" size="sm">+{{ $file['additions'] }}</flux:badge>
                            @endif
                            @if($file['deletions'] > 0)
                                <flux:badge color="red" size="sm">-{{ $file['deletions'] }}</flux:badge>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
            <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-gh-accent/40 transition-colors"
                style="padding-left: 3px; padding-right: 3px; margin-left: -3px; margin-right: -3px; background-clip: content-box;"
                @mousedown="startResize($event)"></div>
        </aside>

        {{-- Main content --}}
        <main class="flex-1 min-w-0 pb-24" :class="resizing && 'pointer-events-none'" style="contain: inline-size layout style">
            @if($gitError)
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <flux:icon icon="exclamation-triangle" variant="outline" class="mx-auto mb-3 text-red-400" />
                        <flux:heading class="mb-2">Git error</flux:heading>
                        <flux:text variant="subtle" size="sm" class="font-mono max-w-lg">{{ $gitError }}</flux:text>
                    </div>
                </div>
            @elseif(empty($files))
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <flux:icon icon="document-magnifying-glass" variant="outline" class="mx-auto mb-3 text-gh-muted" />
                        <flux:heading class="mb-2">No changes detected</flux:heading>
                        <flux:text variant="subtle" size="sm">Make some changes and run rfa again</flux:text>
                    </div>
                </div>
            @else
                @foreach($files as $file)
                    <div id="{{ $file['id'] }}" class="border-b border-gh-border" x-show="fileMatchesFilter({{ Js::from($file['path']) }})">
                        <livewire:diff-file
                            :key="$file['id']"
                            :file="$file"
                            :load-delay="(int) (floor($loop->index / 15) * 100)"
                            :file-comments="$this->groupedComments[$file['id']] ?? []"
                            :is-viewed="in_array($file['path'], $viewedFiles)"
                            :repo-path="$repoPath"
                            :project-id="$projectId"
                        />
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>
