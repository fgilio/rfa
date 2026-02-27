<?php

use App\Actions\AddCommentAction;
use App\Actions\DeleteCommentAction;
use App\Actions\DeleteReviewFilesAction;
use App\Actions\ExportReviewAction;
use App\Actions\GetFileListAction;
use App\Actions\GroupReviewFilesAction;
use App\Actions\ResolveProjectAction;
use App\Actions\RestoreSessionAction;
use App\Actions\SaveSessionAction;
use App\Actions\ToggleViewedAction;
use App\Exceptions\GitCommandException;
use App\Models\Project;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
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

    public function mount(string $slug): void
    {
        $project = app(ResolveProjectAction::class)->handle($slug);
        $this->repoPath = $project['path'];
        $this->projectId = $project['id'];
        $this->projectName = $project['name'];
        $this->projectBranch = $project['branch'] ?? '';
        $this->projectSlug = $project['slug'];
        $this->respectGlobalGitignore = $project['respect_global_gitignore'] ?? true;
        $this->globalGitignorePath = $project['global_gitignore_path'] ?: null;

        // Backfill path for projects registered before the migration
        if ($this->globalGitignorePath === null) {
            $this->globalGitignorePath = app(\App\Services\GitDiffService::class)
                ->resolveGlobalExcludesFile($this->repoPath);

            if ($this->globalGitignorePath !== null) {
                Project::where('id', $this->projectId)
                    ->update(['global_gitignore_path' => $this->globalGitignorePath]);
            }
        }

        try {
            $this->files = app(GetFileListAction::class)->handle(
                $this->repoPath,
                projectId: $this->projectId,
                globalGitignorePath: $this->respectGlobalGitignore ? $this->globalGitignorePath : null,
            );
        } catch (GitCommandException $e) {
            $this->gitError = $e->stderr ?: $e->getMessage();
            $this->files = [];
        }

        $this->groupFiles();

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files, $this->projectId);
        $this->comments = $session['comments'];
        $this->viewedFiles = $session['viewedFiles'];
        $this->globalComment = $session['globalComment'];
    }

    public function updatedRespectGlobalGitignore(): void
    {
        Project::where('id', $this->projectId)->update([
            'respect_global_gitignore' => $this->respectGlobalGitignore,
        ]);

        try {
            $this->files = app(GetFileListAction::class)->handle(
                $this->repoPath,
                projectId: $this->projectId,
                globalGitignorePath: $this->respectGlobalGitignore ? $this->globalGitignorePath : null,
            );
        } catch (GitCommandException $e) {
            $this->gitError = $e->stderr ?: $e->getMessage();
            $this->files = [];
        }

        $this->groupFiles();

        $session = app(RestoreSessionAction::class)->handle($this->repoPath, $this->files, $this->projectId);
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

        // Refresh file list to include newly created review files
        $this->files = app(GetFileListAction::class)->handle(
            $this->repoPath,
            clearCache: false,
            projectId: $this->projectId,
            globalGitignorePath: $this->respectGlobalGitignore ? $this->globalGitignorePath : null,
        );
        $this->groupFiles();

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
        app(SaveSessionAction::class)->handle($this->repoPath, $this->comments, $this->viewedFiles, $this->globalComment, $this->projectId);
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
                    ? '{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}'
                    : filePaths.filter(p => fileMatchesFilter(p)).length + '/{{ count($sourceFiles) }} files'"
            >{{ count($sourceFiles) }} {{ Str::plural('file', count($sourceFiles)) }}</flux:text>
            <flux:text variant="subtle" size="sm" inline
                x-show="Object.values(viewedFiles).filter(Boolean).length > 0"
                x-text="Object.values(viewedFiles).filter(Boolean).length + '/{{ count($sourceFiles) }} viewed'"
                x-cloak />
            @if(count($reviewPairs) > 0)
                <flux:badge color="purple" size="sm">{{ count($reviewPairs) }} {{ Str::plural('review', count($reviewPairs)) }}</flux:badge>
            @endif
            <flux:badge color="green" size="sm">+{{ collect($sourceFiles)->sum('additions') }}</flux:badge>
            <flux:badge color="red" size="sm">-{{ collect($sourceFiles)->sum('deletions') }}</flux:badge>
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:checkbox wire:model.live="respectGlobalGitignore"
                label="Global .gitignore" class="text-xs" />
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:tooltip content="Collapse all (Shift+C)">
                <flux:button variant="ghost" size="sm" icon="collapse-all"
                    @click="$dispatch('collapse-all-files')" />
            </flux:tooltip>
            <flux:tooltip content="Expand all (Shift+E)">
                <flux:button variant="ghost" size="sm" icon="expand-all"
                    @click="$dispatch('expand-all-files')" />
            </flux:tooltip>
            <span class="w-px h-4 bg-gh-border"></span>
            <div x-data="{
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
                    <flux:button variant="ghost" size="sm" icon="arrow-path"
                        @click="refresh()" />
                </flux:tooltip>
                <span x-show="hasChanges" x-cloak
                    class="absolute -top-0.5 -right-0.5 flex h-2.5 w-2.5">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-amber-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-amber-500"></span>
                </span>
            </div>
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
                @if(count($reviewPairs) > 0)
                    <div class="flex items-center justify-between mb-2">
                        <flux:heading class="!text-xs uppercase tracking-wide">Reviews</flux:heading>
                        @if(count($reviewPairs) > 1)
                            <button class="text-gh-muted hover:text-red-400 transition-colors"
                                @click="if (confirm('Delete all review files?')) $wire.deleteAllReviewPairs()">
                                <flux:icon icon="trash" variant="micro" />
                            </button>
                        @endif
                    </div>
                    @foreach($reviewPairs as $pair)
                        <div class="w-full text-left px-2 py-1.5 rounded text-xs hover:bg-gh-border/50 flex items-center gap-2 group transition-colors text-gh-text">
                            <flux:badge variant="solid" color="purple" size="sm" class="!text-[10px] !px-1 !py-0 w-4 shrink-0 justify-center">R</flux:badge>
                            <button @click="scrollToFile('{{ $pair['id'] }}')" class="truncate text-left" title="{{ $pair['basename'] }}">
                                {{ $pair['displayName'] }}
                            </button>
                            <button class="opacity-0 group-hover:opacity-100 transition-opacity text-red-400 hover:text-red-300 shrink-0 ml-auto"
                                @click="if (confirm('Delete this review?')) $wire.deleteReviewPair('{{ $pair['basename'] }}')">
                                <flux:icon icon="trash" variant="micro" />
                            </button>
                        </div>
                    @endforeach
                    <div class="border-b border-gh-border my-2"></div>
                @endif

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
                        class="w-full text-left px-2 py-1.5 rounded text-xs hover:bg-gh-border/50 flex items-center gap-2 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-border/50 text-gh-accent' : 'text-gh-text'"
                    >
                        <flux:badge variant="solid" :color="$badgeColor" size="sm" class="!text-[10px] !px-1 !py-0 w-4 shrink-0 justify-center">{{ $badgeLabel }}</flux:badge>
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
                @mousedown="startResize($event)"
                @dblclick="sidebarWidth = 288; localStorage.setItem('rfa-sidebar-width', 288)"></div>
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
                {{-- Review Pairs --}}
                @foreach($reviewPairs as $pair)
                    <div id="{{ $pair['id'] }}" class="border-b border-gh-border" x-data="{ collapsed: true }">
                        <div class="sticky top-[var(--header-h)] z-10 bg-gh-surface border-b border-gh-border px-4 py-2 flex items-center gap-2">
                            <button @click="collapsed = !collapsed" class="text-gh-muted hover:text-gh-text transition-colors">
                                <flux:icon icon="chevron-down" variant="micro" x-show="!collapsed" />
                                <flux:icon icon="chevron-right" variant="micro" x-show="collapsed" x-cloak />
                            </button>
                            <flux:badge variant="solid" color="purple" size="sm" class="!text-[10px] !px-1 !py-0 w-4 shrink-0 justify-center">R</flux:badge>
                            <span class="font-mono text-sm truncate">{{ $pair['displayName'] }}</span>
                            @if($pair['jsonFile'])
                                <flux:badge size="sm" variant="outline">.json</flux:badge>
                            @endif
                            @if($pair['mdFile'])
                                <flux:badge size="sm" variant="outline">.md</flux:badge>
                            @endif
                            <span class="ml-auto">
                                <flux:button variant="ghost" size="sm" icon="trash"
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
                                />
                            @endif
                        </div>
                    </div>
                @endforeach

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
                        />
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>
