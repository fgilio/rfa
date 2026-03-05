<?php

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\BackfillGlobalGitignoreAction;
use App\Actions\LoadCommitMetadataAction;
use App\Actions\ResolveCommitAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use App\DTOs\DiffTarget;
use App\Exceptions\GitCommandException;
use App\Actions\UpdateProjectSettingAction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component {
    /** @var array<int, array<string, mixed>> */
    public array $files = [];

    /** @var array<int, array<string, mixed>> */
    public array $reviewPairs = [];

    /** @var array<int, array<string, mixed>> */
    public array $sourceFiles = [];

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

    public bool $respectGlobalGitignore = true;

    public ?string $globalGitignorePath = null;

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    /** @var array{shortHash: string, message: string, author: string, prevHash: ?string, nextHash: ?string}|null */
    #[Locked]
    public ?array $commitInfo = null;

    public function mount(string $slug, ?string $hash = null, ?string $ref = null, ?string $baseRef = null): void
    {
        $project = app(ResolveProjectAction::class)->handle($slug);
        $this->repoPath = $project['path'];
        $this->projectId = $project['id'];
        $this->projectName = $project['name'];
        $this->projectBranch = $project['branch'] ?? '';
        $this->projectSlug = $project['slug'];
        $this->respectGlobalGitignore = $project['respect_global_gitignore'] ?? true;
        $this->globalGitignorePath = $project['global_gitignore_path'] ?: null;

        // Commit mode: resolve hash to full SHA
        if ($hash !== null) {
            $target = app(ResolveCommitAction::class)->handle($this->repoPath, $hash);

            if ($target === null) {
                abort(404, 'Invalid commit reference');
            }

            $this->diffFrom = $target->from();
            $this->diffTo = $target->to();
            $this->loadCommitInfo();
        } elseif ($ref !== null) {
            // Range mode from URL params
            $this->diffTo = $ref;
            $this->diffFrom = $baseRef ?? $ref.'^';
        }

        // Backfill path for projects registered before the migration
        if ($this->globalGitignorePath === null) {
            $this->globalGitignorePath = app(BackfillGlobalGitignoreAction::class)
                ->handle($this->projectId, $this->repoPath);
        }

        $this->refreshFileList();

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files, $this->projectId, $this->buildDiffTarget()->contextKey());
        $this->comments = $session['comments'];
        $this->viewedFiles = $session['viewedFiles'];
        $this->globalComment = $session['globalComment'];
    }

    public function isCommitMode(): bool
    {
        return $this->diffTo !== null;
    }

    private ?DiffTarget $cachedTarget = null;

    private function buildDiffTarget(): DiffTarget
    {
        return $this->cachedTarget ??= DiffTarget::fromRefs($this->diffFrom, $this->diffTo);
    }

    private function loadCommitInfo(): void
    {
        if ($this->diffTo === null) {
            return;
        }

        $this->commitInfo = app(LoadCommitMetadataAction::class)
            ->handle($this->repoPath, $this->diffTo, $this->diffFrom);
    }

    private function refreshFileList(bool $clearCache = true): void
    {
        $target = $this->buildDiffTarget();

        try {
            $this->files = app(GetFileListAction::class)->handle(
                $this->repoPath,
                clearCache: $clearCache,
                projectId: $this->projectId,
                globalGitignorePath: $this->diffTo !== null ? null : ($this->respectGlobalGitignore ? $this->globalGitignorePath : null),
                target: $target,
            );
        } catch (GitCommandException $e) {
            $this->gitError = $e->stderr ?: $e->getMessage();
            $this->files = [];
        }

        $this->groupFiles();
    }

    public function updatedRespectGlobalGitignore(): void
    {
        app(UpdateProjectSettingAction::class)->handle($this->projectId, [
            'respect_global_gitignore' => $this->respectGlobalGitignore,
        ]);

        $this->refreshFileList();

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files, $this->projectId, $this->buildDiffTarget()->contextKey());
        $this->comments = $session['comments'];
        $this->viewedFiles = $session['viewedFiles'];
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

    #[On('add-draft-comment')]
    public function addDraftComment(string $fileId, string $side, ?int $startLine, ?int $endLine, string $body): void
    {
        $comment = app(AddCommentAction::class)->handle($this->files, $fileId, $side, $startLine, $endLine, $body, isDraft: true);

        if (! $comment) {
            return;
        }

        $this->comments[] = $comment;
        $this->saveSession();
        $this->dispatchFileComments($fileId);
        $this->skipRender();
    }

    #[On('update-comment')]
    public function updateComment(string $commentId, string $body, bool $isDraft = false): void
    {
        $index = collect($this->comments)->search(fn ($c) => $c['id'] === $commentId);

        if ($index === false) {
            return;
        }

        $this->comments[$index]['body'] = $body;
        $this->comments[$index]['isDraft'] = $isDraft;
        $fileId = $this->comments[$index]['fileId'];

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

        $finalizedComments = array_values(array_filter($this->comments, fn ($c) => ! ($c['isDraft'] ?? false)));

        $result = app(ExportReviewAction::class)->handle($this->repoPath, $finalizedComments, $this->globalComment, $this->files);

        $this->exportResult = $result['clipboard'];
        $this->submitted = true;

        // Refresh file list to include newly created review files
        $this->refreshFileList(clearCache: false);

        Flux::toast(variant: 'success', heading: 'Review submitted', text: $this->exportResult);
        $this->dispatch('copy-to-clipboard', text: $result['clipboard']);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    #[Computed]
    public function groupedComments(): array
    {
        return collect($this->comments)->groupBy('fileId')->map(fn ($group) => $group->values()->all())->all();
    }

    public function deleteReviewPair(string $basename): void
    {
        app(DeleteReviewFilesAction::class)->handle($this->repoPath, $basename);

        $this->reviewPairs = array_values(
            array_filter($this->reviewPairs, fn ($p) => $p['basename'] !== $basename)
        );

        $this->files = array_values(
            array_filter($this->files, function ($f) use ($basename) {
                return \App\DTOs\ReviewFilePair::extractBasename($f['path']) !== $basename;
            })
        );

        Flux::toast(text: 'Review deleted', variant: 'success');
    }

    public function deleteAllReviewPairs(): void
    {
        $basenames = array_column($this->reviewPairs, 'basename');

        if (empty($basenames)) {
            return;
        }

        app(DeleteReviewFilesAction::class)->handle($this->repoPath, $basenames);

        $this->files = array_values(
            array_filter($this->files, function ($f) {
                return \App\DTOs\ReviewFilePair::extractBasename($f['path']) === null;
            })
        );

        $this->reviewPairs = [];

        Flux::toast(text: 'All reviews deleted', variant: 'success');
    }

    private function groupFiles(): void
    {
        $grouped = app(GroupReviewFilesAction::class)->handle($this->files);
        $this->reviewPairs = $grouped['reviewPairs'];
        $this->sourceFiles = $grouped['sourceFiles'];
    }

    private function dispatchFileComments(string $fileId): void
    {
        $fileComments = collect($this->comments)->where('fileId', $fileId)->values()->all();
        $this->dispatch('comment-updated', fileId: $fileId, comments: $fileComments);
    }

    private function saveSession(): void
    {
        app(SaveSessionAction::class)->handle($this->repoPath, $this->comments, $this->viewedFiles, $this->globalComment, $this->projectId, $this->buildDiffTarget()->contextKey());
    }
};
?>

<div
    data-testid="review-component"
    x-data="{
        activeFile: null,
        viewedFiles: {{ Js::from((object) collect($sourceFiles)->filter(fn($f) => in_array($f['path'], $viewedFiles))->pluck('id')->flip()->map(fn() => true)->all()) }},
        fileFilter: '',
        filePaths: {{ Js::from(collect($sourceFiles)->pluck('path')->all()) }},
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
            if ($event.key === 'Escape' && !$event.target.closest('[data-comment-form]')) { fileFilter = ''; $event.target.blur(); $event.preventDefault(); }
            return;
        }
        if ($event.key === '/') { $refs.fileFilterInput?.focus(); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'C') { $dispatch('collapse-all-files'); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'E') { $dispatch('expand-all-files'); $event.preventDefault(); }
        @if($commitInfo)
            if ($event.key === '[' && {{ Js::from($commitInfo['prevHash']) }}) { Livewire.navigate('/p/{{ $projectSlug }}/c/' + {{ Js::from($commitInfo['prevHash']) }}); $event.preventDefault(); }
            if ($event.key === ']' && {{ Js::from($commitInfo['nextHash']) }}) { Livewire.navigate('/p/{{ $projectSlug }}/c/' + {{ Js::from($commitInfo['nextHash']) }}); $event.preventDefault(); }
        @endif
    "
>
    {{-- Header --}}
    <header class="sticky top-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-b border-gh-border px-5 py-3.5 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <a href="/" class="hover:opacity-70 transition-opacity"><span class="rfa-logo text-xl">rfa</span></a>
            <span class="text-gh-border select-none">/</span>
            <span class="font-medium tracking-brutal text-sm">{{ $projectName }}</span>
            @if($projectBranch)
                <livewire:branch-explorer :repo-path="$repoPath" :current-branch="$projectBranch" :project-slug="$projectSlug" :active-commit-hash="$diffTo" />
            @endif
        </div>
        <div class="flex items-center gap-3 text-xs">
            <span class="font-mono text-gh-muted"
                x-text="fileFilter === ''
                    ? '{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}'
                    : filePaths.filter(p => fileMatchesFilter(p)).length + '/{{ count($sourceFiles) }} files'"
            >{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}</span>
            <span class="font-mono text-gh-muted"
                x-show="Object.values(viewedFiles).filter(Boolean).length > 0"
                x-text="Object.values(viewedFiles).filter(Boolean).length + '/{{ count($sourceFiles) }} viewed'"
                x-cloak></span>
            @if(count($reviewPairs) > 0)
                <span class="font-mono text-xs text-gh-muted px-1.5 py-0.5 rounded border border-gh-border">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</span>
            @endif
            <span class="font-mono text-gh-green">+{{ collect($sourceFiles)->sum('additions') }}</span>
            <span class="font-mono text-gh-red">-{{ collect($sourceFiles)->sum('deletions') }}</span>
            @if(! $this->isCommitMode())
                <span class="w-px h-4 bg-gh-border"></span>
                <flux:checkbox wire:model.live="respectGlobalGitignore"
                    label="Global .gitignore" class="text-xs" />
            @endif
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:tooltip content="Expand all (Shift+E)">
                <flux:button variant="ghost" size="sm" icon="expand-all" icon:variant="outline"
                    @click="$dispatch('expand-all-files')" />
            </flux:tooltip>
            <flux:tooltip content="Collapse all (Shift+C)">
                <flux:button variant="ghost" size="sm" icon="collapse-all" icon:variant="outline"
                    @click="$dispatch('collapse-all-files')" />
            </flux:tooltip>
            @if(! $this->isCommitMode())
                <span class="w-px h-4 bg-gh-border"></span>
                <div data-testid="change-polling" x-data="{
                    hasChanges: false,
                    fingerprint: null,
                    polling: null,
                    async check() {
                        try {
                            const res = await fetch('/api/changes/{{ $projectId }}');
                            const data = await res.json();
                            if (this.fingerprint === null) {
                                this.fingerprint = data.fingerprint;
                            } else if (data.fingerprint !== this.fingerprint) {
                                this.hasChanges = true;
                            }
                        } catch {}
                    },
                    startPolling() {
                        this.check();
                        this.polling = setInterval(() => {
                            if (!document.hidden) this.check();
                        }, 60000);
                    },
                    refresh() {
                        window.location.reload();
                    }
                }" x-init="startPolling()" class="relative flex items-center">
                    <flux:tooltip content="Refresh page">
                        <flux:button variant="ghost" size="sm" icon="arrow-path" icon:variant="outline"
                            @click="refresh()" />
                    </flux:tooltip>
                    <span x-show="hasChanges" x-cloak
                        class="absolute -top-0.5 -right-0.5 flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                    </span>
                </div>
            @endif
            <span class="w-px h-4 bg-gh-border"></span>
            <livewire:theme-switcher />
        </div>
    </header>

    {{-- Commit context bar --}}
    @if($commitInfo)
        <div data-testid="commit-context-bar" class="sticky top-[var(--header-h)] z-40 bg-gh-surface border-b border-gh-border px-5 py-2.5 flex items-center gap-3 text-xs" style="--commit-bar-h: 40px;">
            <flux:icon icon="code-bracket" variant="outline" class="text-gh-muted shrink-0" />
            <span class="font-mono text-xs text-gh-muted shrink-0 px-1.5 py-0.5 rounded border border-gh-border">{{ $commitInfo['shortHash'] }}</span>
            <span class="text-gh-text truncate font-medium">{{ $commitInfo['message'] }}</span>
            <span class="text-gh-muted shrink-0">{{ $commitInfo['author'] }}</span>
            <div class="ml-auto flex items-center gap-1 shrink-0">
                @if($commitInfo['prevHash'])
                    <flux:tooltip content="Previous commit ([)">
                        <flux:button aria-label="Previous commit" variant="ghost" size="xs" icon="chevron-left" icon:variant="outline"
                            onclick="Livewire.navigate('/p/{{ $projectSlug }}/c/{{ $commitInfo['prevHash'] }}')" />
                    </flux:tooltip>
                @endif
                @if($commitInfo['nextHash'])
                    <flux:tooltip content="Next commit (])">
                        <flux:button aria-label="Next commit" variant="ghost" size="xs" icon="chevron-right" icon:variant="outline"
                            onclick="Livewire.navigate('/p/{{ $projectSlug }}/c/{{ $commitInfo['nextHash'] }}')" />
                    </flux:tooltip>
                @endif
                <flux:tooltip content="Back to working directory">
                    <flux:button aria-label="Back to working directory" variant="ghost" size="xs" icon="x-mark" icon:variant="outline"
                        onclick="Livewire.navigate('/p/{{ $projectSlug }}')" />
                </flux:tooltip>
            </div>
        </div>
    @endif

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-bg hidden lg:block" :style="{ width: sidebarWidth + 'px' }" x-ref="sidebar">
            <div class="p-4">
                @if(! $this->isCommitMode() && count($reviewPairs) > 0)
                    <div class="flex items-center justify-between mb-3">
                        <span class="section-label text-gh-muted">Reviews</span>
                        @if(count($reviewPairs) > 1)
                            <button class="text-gh-muted hover:text-red-400 transition-colors"
                                @click="if (confirm('Delete all review files?')) $wire.deleteAllReviewPairs()">
                                <flux:icon icon="trash" variant="outline" class="!size-4" />
                            </button>
                        @endif
                    </div>
                    @foreach($reviewPairs as $pair)
                        <div class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-colors text-gh-text">
                            <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                            <button @click="scrollToFile('{{ $pair['id'] }}')" class="truncate text-left font-mono" title="{{ $pair['basename'] }}">
                                {{ $pair['displayName'] }}
                            </button>
                            <button class="opacity-0 group-hover:opacity-100 transition-opacity text-red-400 hover:text-red-300 shrink-0 ml-auto"
                                @click="if (confirm('Delete this review?')) $wire.deleteReviewPair('{{ $pair['basename'] }}')">
                                <flux:icon icon="trash" variant="outline" class="!size-4" />
                            </button>
                        </div>
                    @endforeach
                    <div class="border-b border-gh-border my-3"></div>
                @endif

                <span class="section-label text-gh-muted mb-3 block">Files</span>
                <flux:input
                    x-model.debounce.150ms="fileFilter"
                    placeholder="Filter files..."
                    icon="magnifying-glass"
                    icon:variant="outline"
                    clearable
                    kbd="/"
                    size="sm"
                    variant="filled"
                    class="mb-3"
                    x-ref="fileFilterInput"
                    @keydown.escape="fileFilter = ''; $el.blur()"
                />
                @foreach($sourceFiles as $file)
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
                        class="w-full text-left px-2.5 py-2 rounded text-xs hover:bg-gh-border/30 flex items-center gap-2.5 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-link/10 text-gh-link' : 'text-gh-muted'"
                    >
                        <span class="font-mono font-medium shrink-0 {{ match($badgeLabel) { 'A' => 'text-gh-green', 'D' => 'text-gh-red', default => 'text-amber-500 dark:text-amber-400' } }}">{{ $badgeLabel }}</span>
                        <span class="truncate font-mono" title="{{ $file['path'] }}{{ ($file['lastModified'] ?? null) ? "\nModified " . $file['lastModified'] : '' }}">{{ $file['path'] }}</span>
                        <flux:icon icon="check" variant="outline" x-show="viewedFiles['{{ $file['id'] }}']"
                            class="text-gh-green shrink-0" x-cloak />
                        <span class="ml-auto flex gap-1.5 shrink-0 font-mono">
                            @if($file['additions'] > 0)
                                <span class="text-gh-green">+{{ $file['additions'] }}</span>
                            @endif
                            @if($file['deletions'] > 0)
                                <span class="text-gh-red">-{{ $file['deletions'] }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </aside>
        <div data-testid="sidebar-resize-handle" class="group/resize hidden lg:flex sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] w-0 cursor-col-resize items-center justify-center z-10 shrink-0"
            style="padding: 0 6px; margin: 0 -6px;"
            @mousedown="startResize($event)"
            @dblclick="sidebarWidth = 288; localStorage.setItem('rfa-sidebar-width', 288)">
            <div class="absolute inset-y-0 w-px bg-transparent group-hover/resize:bg-gh-muted/40 transition-colors"></div>
            <div class="absolute px-1 py-1.5 rounded-full bg-gh-surface border border-gh-border shadow-sm opacity-0 group-hover/resize:opacity-100 transition-opacity pointer-events-none flex flex-col items-center gap-[3px]">
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
                <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
            </div>
        </div>

        {{-- Main content --}}
        <main class="flex-1 min-w-0 pb-24" :class="resizing && 'pointer-events-none'" style="contain: inline-size layout style">
            @if($gitError)
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center max-w-lg">
                        <p class="rfa-logo text-3xl text-red-400/30 mb-4">!</p>
                        <h2 class="font-semibold tracking-brutal text-lg mb-2">Git error</h2>
                        <p class="font-mono text-xs text-gh-muted leading-relaxed">{{ $gitError }}</p>
                    </div>
                </div>
            @elseif(empty($files))
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <p class="rfa-logo text-5xl text-gh-muted/20 mb-6">rfa</p>
                        @if($this->isCommitMode())
                            <h2 class="font-semibold tracking-brutal text-lg mb-2">No file changes in this commit</h2>
                            <p class="text-sm text-gh-muted">This commit has no diff (empty or merge commit)</p>
                        @else
                            <h2 class="font-semibold tracking-brutal text-lg mb-2">No changes detected</h2>
                            <p class="text-sm text-gh-muted">Make some changes and run rfa again</p>
                        @endif
                    </div>
                </div>
            @else
                {{-- Review Pairs (working directory mode only) --}}
                @if(! $this->isCommitMode())
                    @foreach($reviewPairs as $pair)
                        <div id="{{ $pair['id'] }}" class="border-b border-gh-border" x-data="{ collapsed: true }">
                            <div class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">
                                <button @click="collapsed = !collapsed" class="text-gh-muted hover:text-gh-text transition-colors">
                                    <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
                                    <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
                                </button>
                                <span class="text-[10px] font-mono font-medium text-purple-500 dark:text-purple-400 shrink-0">R</span>
                                <span class="font-mono text-sm truncate">{{ $pair['displayName'] }}</span>
                                @if($pair['jsonFile'])
                                    <span class="text-[10px] font-mono text-gh-muted">.json</span>
                                @endif
                                @if($pair['mdFile'])
                                    <span class="text-[10px] font-mono text-gh-muted">.md</span>
                                @endif
                                <span class="ml-auto">
                                    <flux:button variant="ghost" size="sm" icon="trash" icon:variant="outline"
                                        @click="if (confirm('Delete this review?')) $wire.deleteReviewPair('{{ $pair['basename'] }}')" />
                                </span>
                            </div>
                            <div x-show="!collapsed" x-collapse.duration.150ms>
                                @if($pair['jsonFile'])
                                    <livewire:diff-file
                                        :key="$pair['jsonFile']['id']"
                                        :file="$pair['jsonFile']"
                                        :load-delay="0"
                                        :file-comments="$this->groupedComments[$pair['jsonFile']['id']] ?? []"
                                        :is-viewed="in_array($pair['jsonFile']['path'], $viewedFiles)"
                                        :repo-path="$repoPath"
                                        :project-id="$projectId"
                                        :diff-from="$diffFrom"
                                        :diff-to="$diffTo"
                                    />
                                @endif
                                @if($pair['mdFile'])
                                    <livewire:diff-file
                                        :key="$pair['mdFile']['id']"
                                        :file="$pair['mdFile']"
                                        :load-delay="0"
                                        :file-comments="$this->groupedComments[$pair['mdFile']['id']] ?? []"
                                        :is-viewed="in_array($pair['mdFile']['path'], $viewedFiles)"
                                        :repo-path="$repoPath"
                                        :project-id="$projectId"
                                        :diff-from="$diffFrom"
                                        :diff-to="$diffTo"
                                    />
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif

                {{-- Source Files --}}
                @foreach($sourceFiles as $file)
                    <div id="{{ $file['id'] }}" class="border-b border-gh-border" x-show="fileMatchesFilter({{ Js::from($file['path']) }})">
                        <livewire:diff-file
                            :key="$file['id']"
                            :file="$file"
                            :load-delay="(int) (floor($loop->index / 15) * 100)"
                            :file-comments="$this->groupedComments[$file['id']] ?? []"
                            :is-viewed="in_array($file['path'], $viewedFiles)"
                            :repo-path="$repoPath"
                            :project-id="$projectId"
                            :diff-from="$diffFrom"
                            :diff-to="$diffTo"
                        />
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>
