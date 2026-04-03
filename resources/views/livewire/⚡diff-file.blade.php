<?php

use App\Actions\GetFileCopyContentAction;
use App\Actions\LoadFileDiffAction;
use App\DTOs\DiffTarget;
use App\Support\DiffCacheKey;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    /** @var array<string, mixed> */
    #[Locked]
    public array $file = [];

    #[Locked]
    public string $repoPath = '';

    #[Locked]
    public int $projectId = 0;

    #[Locked]
    public int $loadDelay = 0;

    #[Locked]
    public string $diffFrom = 'HEAD';

    #[Locked]
    public ?string $diffTo = null;

    public bool $isViewed = false;

    /** @var array<int, array<string, mixed>> */
    public array $fileComments = [];

    /** @var array<string, mixed>|null */
    protected ?array $diffData = null;

    private bool $contextExpanded = false;

    private ?DiffTarget $cachedTarget = null;

    public function hydrate(): void
    {
        $this->diffData = Cache::get($this->diffCacheKey());
    }

    /** @param array<int, array<string, mixed>> $comments */
    public function updateComments(array $comments): void
    {
        $this->fileComments = $comments;
        $this->ensureCommentedLinesVisible();
    }

    public function loadFileDiff(): void
    {
        if ($this->diffData !== null) {
            return;
        }

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $this->diffCacheKey(),
            target: $this->buildDiffTarget(),
        );

        $this->ensureCommentedLinesVisible();
    }

    public function copyContent(string $kind): void
    {
        $text = app(GetFileCopyContentAction::class)->handle(
            $kind,
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            $this->buildDiffTarget(),
            oldPath: $this->file['oldPath'] ?? null,
        );

        if ($text !== null) {
            $this->dispatch('copy-to-clipboard', text: $text);
        }
    }

    public function expandContext(): void
    {
        $cacheKey = $this->diffCacheKey();
        Cache::forget($cacheKey);

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $cacheKey,
            contextLines: 99999,
            target: $this->buildDiffTarget(),
        );
    }

    public function expandGap(int $hunkIndex): void
    {
        if ($this->diffData === null || empty($this->diffData['hunks'])) {
            return;
        }

        $hunks = $this->diffData['hunks'];

        // Determine the new-line range for this gap
        $isTrailing = $hunkIndex === count($hunks);

        if ($isTrailing) {
            $last = $hunks[count($hunks) - 1];
            $gapNewStart = $last['newStart'] + $last['newCount'];
            $gapNewEnd = $this->diffData['newFileLineCount'] ?? $gapNewStart;
        } elseif ($hunkIndex === 0) {
            $gapNewStart = 1;
            $gapNewEnd = $hunks[0]['newStart'] - 1;
        } else {
            $prev = $hunks[$hunkIndex - 1];
            $gapNewStart = $prev['newStart'] + $prev['newCount'];
            $gapNewEnd = $hunks[$hunkIndex]['newStart'] - 1;
        }

        if ($gapNewStart > $gapNewEnd) {
            return;
        }

        // Fetch full-context diff to get the hidden lines with syntax highlighting
        $fullDiff = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            contextLines: 99999,
            target: $this->buildDiffTarget(),
        );

        if (empty($fullDiff['hunks'])) {
            return;
        }

        // Extract gap lines from the full diff's single hunk by newLineNum
        $gapLines = collect($fullDiff['hunks'][0]['lines'])
            ->filter(function (array $line) use ($gapNewStart, $gapNewEnd): bool {
                $num = $line['newLineNum'] ?? null;

                return $num !== null
                    && $num >= $gapNewStart
                    && $num <= $gapNewEnd
                    && $line['type'] === 'context';
            })
            ->values()
            ->all();

        if (empty($gapLines)) {
            return;
        }

        $gapSize = count($gapLines);

        if ($isTrailing) {
            // Append gap lines to last hunk
            $lastIdx = count($hunks) - 1;
            $hunks[$lastIdx]['lines'] = array_merge($hunks[$lastIdx]['lines'], $gapLines);
            $hunks[$lastIdx]['oldCount'] += $gapSize;
            $hunks[$lastIdx]['newCount'] += $gapSize;
        } elseif ($hunkIndex === 0) {
            // Prepend gap lines to first hunk
            $hunks[0]['lines'] = array_merge($gapLines, $hunks[0]['lines']);
            $hunks[0]['oldStart'] -= $gapSize;
            $hunks[0]['oldCount'] += $gapSize;
            $hunks[0]['newStart'] = 1;
            $hunks[0]['newCount'] += $gapSize;
        } else {
            // Merge: prevHunk + gapLines + currentHunk -> single hunk
            $prev = $hunks[$hunkIndex - 1];
            $curr = $hunks[$hunkIndex];

            $merged = [
                'header' => $prev['header'],
                'oldStart' => $prev['oldStart'],
                'oldCount' => $prev['oldCount'] + $gapSize + $curr['oldCount'],
                'newStart' => $prev['newStart'],
                'newCount' => $prev['newCount'] + $gapSize + $curr['newCount'],
                'lines' => array_merge($prev['lines'], $gapLines, $curr['lines']),
            ];

            array_splice($hunks, $hunkIndex - 1, 2, [$merged]);
        }

        $this->diffData['hunks'] = $hunks;

        // Merge syntax styles from the full diff
        if (! empty($fullDiff['syntaxStyles'])) {
            $this->diffData['syntaxStyles'] = ($this->diffData['syntaxStyles'] ?? '').$fullDiff['syntaxStyles'];
        }

        // Update cache with expanded state
        Cache::put($this->diffCacheKey(), $this->diffData, now()->addHours($this->buildDiffTarget()->cacheTtlHours()));
    }

    private function ensureCommentedLinesVisible(): void
    {
        if ($this->contextExpanded || empty($this->fileComments)) {
            return;
        }

        $inlineComments = array_filter($this->fileComments, fn ($c) => ($c['side'] ?? '') !== 'file');
        if (empty($inlineComments)) {
            return;
        }

        // No hunks but has inline comments - load full file
        if ($this->diffData === null || empty($this->diffData['hunks'])) {
            $this->contextExpanded = true;
            $this->expandContext();

            return;
        }

        $visible = $this->getVisibleLineKeys();

        foreach ($inlineComments as $c) {
            $key = ($c['side'] ?? 'right').':'.($c['endLine'] ?? $c['startLine'] ?? 0);
            if (! isset($visible[$key])) {
                $this->contextExpanded = true;
                $this->expandContext();

                return;
            }
        }
    }

    /** @return array<string, true> */
    private function getVisibleLineKeys(): array
    {
        $visible = [];
        foreach ($this->diffData['hunks'] ?? [] as $hunk) {
            foreach ($hunk['lines'] as $line) {
                if (isset($line['oldLineNum'])) {
                    $visible['left:'.$line['oldLineNum']] = true;
                }
                if (isset($line['newLineNum'])) {
                    $visible['right:'.$line['newLineNum']] = true;
                }
            }
        }

        return $visible;
    }

    private function buildDiffTarget(): DiffTarget
    {
        return $this->cachedTarget ??= DiffTarget::fromRefs($this->diffFrom, $this->diffTo);
    }

    private function diffCacheKey(): string
    {
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;

        return DiffCacheKey::for($projectKey, $this->file['id'], $this->buildDiffTarget()->contextKey());
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return $this->view(['diffData' => $this->diffData]);
    }
};
?>

@assets
<script src="/js/diff-file.js"></script>
@endassets

{{-- Single file diff rendering --}}
<div
    x-data="diffFile({
        fileId: @js($file['id']),
        filePath: @js($file['path']),
        isViewed: @js($isViewed ?? false),
    })"
    @mouseup.window="endDrag()"
    @comment-updated.window="if ($event.detail.fileId === fileId) $wire.updateComments($event.detail.comments)"
    @collapse-all-files.window="autoExpandedForComment = false; collapsed = true"
    @expand-all-files.window="autoExpandedForComment = false; collapsed = false"
    @expand-file.window="if ($event.detail.id === fileId) { autoExpandedForComment = false; collapsed = false }"
    class="group"
>
    {{-- File header --}}
    <div data-testid="file-header" class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">

        {{-- Toggle zone: click anywhere here to expand/collapse --}}
        <div data-testid="toggle-zone"
             @click="toggleCollapse($event)"
             class="flex items-center gap-2.5 flex-1 min-w-0 cursor-pointer">
            <button :aria-label="collapsed ? 'Expand file' : 'Collapse file'"
                    class="text-gh-muted hover:text-gh-text transition-colors">
                <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
                <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
            </button>

            <span class="font-mono text-sm truncate">
                @if($file['oldPath'])
                    <span class="text-gh-muted">{{ $file['oldPath'] }} &rarr;</span>
                @endif
                {{ $file['path'] }}
            </span>

            @if($file['isSymlink'] ?? false)
                <flux:icon icon="link" variant="outline" class="!size-3.5 text-gh-muted shrink-0" aria-hidden="true" />
                <span class="font-mono text-xs text-gh-muted">&rarr; {{ $file['symlinkTarget'] }}</span>
            @endif
        </div>

        {{-- Actions toolbar --}}
        <div class="flex items-center gap-2.5 text-xs shrink-0 font-mono">
            <flux:tooltip content="Copy file name">
                <flux:button
                    icon="square-2-stack"
                    icon:variant="outline"
                    variant="ghost"
                    size="sm"
                    @click="$dispatch('copy-to-clipboard', { text: filePath })"
                />
            </flux:tooltip>

            @php
                $showContentCopy = !($file['isBinary'] ?? false)
                    && !($file['isSymlink'] ?? false)
                    && !($diffData['tooLarge'] ?? false);
                $isAdded = ($file['status'] ?? '') === 'added' || ($file['isUntracked'] ?? false);
                $isDeleted = ($file['status'] ?? '') === 'deleted';
            @endphp
            @if($showContentCopy)
                <flux:dropdown position="bottom" align="end">
                    <flux:button icon="ellipsis-vertical" icon:variant="outline" variant="ghost" size="sm" aria-label="Copy content" />
                    <flux:menu>
                        <flux:menu.item icon="code-bracket" @click="$wire.copyContent('diff')">
                            Copy diff
                        </flux:menu.item>
                        <flux:menu.item icon="document-minus" @click="$wire.copyContent('original')" :disabled="$isAdded">
                            Copy original
                        </flux:menu.item>
                        <flux:menu.item icon="document-plus" @click="$wire.copyContent('new')" :disabled="$isDeleted">
                            Copy new
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif

            @if($diffTo === null && ($file['status'] ?? '') !== 'commented')
                <flux:tooltip content="Discard changes">
                    <flux:button
                        icon="arrow-uturn-left"
                        icon:variant="outline"
                        variant="ghost"
                        size="sm"
                        @click="
                            @if(count($fileComments) > 0)
                                if (confirm('Discard changes to {{ basename($file['path']) }} and remove {{ count($fileComments) }} comment{{ count($fileComments) === 1 ? '' : 's' }}? You can restore from Trash for 30 minutes.'))
                            @else
                                if (confirm('Discard all changes to {{ basename($file['path']) }}? You can restore from Trash for 30 minutes.'))
                            @endif
                                $dispatch('discard-file', { fileId: @js($file['id']) })
                        "
                    />
                </flux:tooltip>
            @endif

            @if($file['additions'] > 0)
                <span class="text-gh-green">+{{ $file['additions'] }}</span>
            @endif
            @if($file['deletions'] > 0)
                <span class="text-gh-red">-{{ $file['deletions'] }}</span>
            @endif
            <flux:checkbox x-model="viewed" @change="onViewedChange()" label="Viewed" class="text-xs" />
            <flux:tooltip content="Add file comment">
                <flux:button
                    x-ref="fileCommentBtn"
                    icon="chat-bubble-left"
                    icon:variant="outline"
                    variant="ghost"
                    size="sm"
                    aria-label="Add file comment"
                    @click="openFileComment()"
                    class="ml-2"
                />
            </flux:tooltip>
            <span
                x-show="$wire.fileComments.length"
                x-text="$wire.fileComments.length"
                class="text-[10px] font-mono text-gh-muted tabular-nums"
            ></span>
        </div>

    </div>

    {{-- File body --}}
    <div x-show="!collapsed" x-collapse.duration.150ms>
        {{-- File-level comment form + saved comments --}}
        <div x-ref="fileCommentForm">
            <template x-if="showForm && formSide === 'file'">
                <x-comment-form save="submitComment" placeholder="File comment..." border-class="border-b" />
            </template>
            @foreach($fileComments as $comment)
                @if($comment['side'] === 'file')
                    <div x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
                        <x-comment-display :comment="$comment" border-class="border-b" />
                    </div>
                @endif
            @endforeach
        </div>
        {{-- Unplaced inline comments (line no longer exists in diff) --}}
        @php
            $visibleLines = $this->getVisibleLineKeys();
            $unplacedComments = collect($fileComments)->where('side', '!=', 'file')->filter(function ($c) use ($visibleLines) {
                $key = $c['side'] . ':' . ($c['endLine'] ?? $c['startLine'] ?? 0);
                return !isset($visibleLines[$key]);
            });
        @endphp
        @if($unplacedComments->isNotEmpty())
            @foreach($unplacedComments as $comment)
                <div x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
                    <x-comment-display :comment="$comment" border-class="border-b" />
                </div>
            @endforeach
        @endif
        @if(($file['isSymlink'] ?? false) || ($diffData['isSymlink'] ?? false))
            @php $target = $file['symlinkTarget'] ?? ($diffData['symlinkTarget'] ?? ''); @endphp
            <div class="px-4 py-8 text-center">
                <flux:icon icon="link" variant="outline" class="inline-block text-gh-muted mr-1" aria-hidden="true" />
                <flux:text variant="subtle" size="sm" inline>
                    Symbolic link &rarr; <span class="font-mono">{{ $target }}</span>
                </flux:text>
            </div>
        @elseif($file['isBinary'] && !($file['isImage'] ?? false))
            <div class="px-4 py-8 text-center">
                <flux:text variant="subtle" size="sm">Binary file not shown</flux:text>
                @if(($file['fileSize'] ?? null) || ($file['lastModified'] ?? null))
                    <div class="mt-1">
                        <flux:text variant="subtle" size="sm">
                            {{ $file['fileSize'] ?? '' }}@if(($file['fileSize'] ?? null) && ($file['lastModified'] ?? null)) &middot; @endif@if($file['lastModified'] ?? null)modified {{ $file['lastModified'] }}@endif
                        </flux:text>
                    </div>
                @endif
            </div>
        @elseif($file['isBinary'] && ($file['isImage'] ?? false))
            @php
                $status = $file['status'];
                $hasBeforeImage = in_array($status, ['modified', 'binary', 'renamed', 'deleted']);
                $hasAfterImage = in_array($status, ['modified', 'binary', 'renamed', 'added']);
                $beforePath = $file['oldPath'] ?? $file['path'];
                $beforeRef = $diffTo === null ? 'HEAD' : $diffFrom;
                $afterRef = $diffTo ?? 'working';
            @endphp
            <div class="px-4 py-6 flex items-start justify-center gap-6">
                @if($hasBeforeImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasAfterImage ? 'max-w-[50%]' : '' }}">
                        <flux:badge color="red" size="sm">{{ $status === 'deleted' ? 'Deleted' : 'Before' }}</flux:badge>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="/api/image/{{ $projectId }}/{{ $beforeRef }}/{{ $beforePath }}"
                                alt="{{ $beforePath }}"
                                class="max-h-96 object-contain"
                                loading="lazy"
                                onerror="this.closest('[class*=flex-col]').style.display='none'"
                            >
                        </div>
                    </div>
                @endif
                @if($hasAfterImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasBeforeImage ? 'max-w-[50%]' : '' }}">
                        <flux:badge color="green" size="sm">{{ $status === 'added' ? 'New' : 'After' }}</flux:badge>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="/api/image/{{ $projectId }}/{{ $afterRef }}/{{ $file['path'] }}"
                                alt="{{ $file['path'] }}"
                                class="max-h-96 object-contain"
                                loading="lazy"
                                onerror="this.closest('[class*=flex-col]').style.display='none'"
                            >
                        </div>
                    </div>
                @endif
            </div>
        @elseif($diffData === null)
            {{-- Loading state: trigger lazy load via x-intersect --}}
            <div
                x-intersect.once="setTimeout(() => $wire.loadFileDiff(), {{ $loadDelay }})"
                class="px-4 py-8 text-center"
            >
                <div wire:loading wire:target="loadFileDiff">
                    <flux:icon icon="arrow-path" variant="outline" class="animate-spin inline-block text-gh-muted mr-1" />
                    <flux:text variant="subtle" size="sm" inline>Loading diff...</flux:text>
                </div>
                <div wire:loading.remove wire:target="loadFileDiff">
                    <flux:text variant="subtle" size="sm">Waiting to load...</flux:text>
                </div>
            </div>
        @elseif($diffData['tooLarge'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="inline-block text-gh-muted mr-1" />
                <flux:text variant="subtle" size="sm" inline>File diff too large to display</flux:text>
            </div>
        @elseif($diffData['error'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="outline" class="inline-block text-red-400 mr-1" />
                <flux:text variant="subtle" size="sm" inline>Git error: {{ $diffData['error'] }}</flux:text>
            </div>
        @elseif(empty($diffData['hunks']))
            <div class="px-4 py-8 text-center">
                <flux:text variant="subtle" size="sm">No content changes</flux:text>
            </div>
        @else
            @php
                $commentsByLine = collect($fileComments)->where('side', '!=', 'file')->groupBy(fn($c) => $c['side'] . ':' . $c['endLine']);
                $hunks = $diffData['hunks'];
                $lastHunk = end($hunks);
                $lastHunkEnd = $lastHunk ? $lastHunk['newStart'] + $lastHunk['newCount'] - 1 : 0;
                $newFileLineCount = $diffData['newFileLineCount'] ?? null;
                $hasTrailingGap = $newFileLineCount !== null && $lastHunkEnd < $newFileLineCount;
                $trailingHiddenCount = $hasTrailingGap ? $newFileLineCount - $lastHunkEnd : 0;
                $hasGaps = count($hunks) > 1 || (count($hunks) === 1 && $hunks[0]['newStart'] > 1) || $hasTrailingGap;
            @endphp
            @if($diffData['syntaxStyles'] ?? '')
                {!! '<style>' . $diffData['syntaxStyles'] . '</style>' !!}
            @endif
            <div class="overflow-x-auto">
                <table class="w-full border-collapse font-mono text-xs leading-5" :class="isDragging ? 'select-none' : ''">
                    @if($hasGaps)
                        <tr class="bg-gh-hunk-bg">
                            <td colspan="4" class="px-4 py-1 text-center">
                                <button
                                    wire:click="expandContext"
                                    wire:loading.attr="disabled"
                                    wire:target="expandContext"
                                    class="text-gh-link text-xs hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                >
                                    <flux:icon wire:loading wire:target="expandContext" icon="arrow-path" variant="outline" class="animate-spin" />
                                    Show full file
                                </button>
                            </td>
                        </tr>
                    @endif

                    @foreach($diffData['hunks'] as $hunkIndex => $hunk)
                        {{-- Hunk separator with expand button --}}
                        @if($hunkIndex > 0 || $hunk['header'] !== '')
                            <tr class="bg-gh-hunk-bg">
                                <td colspan="4" class="px-4 py-1 text-gh-muted text-xs">
                                    @if($hunkIndex > 0)
                                        @php
                                            $prevHunk = $hunks[$hunkIndex - 1];
                                            $hiddenCount = $hunk['newStart'] - ($prevHunk['newStart'] + $prevHunk['newCount']);
                                        @endphp
                                        <button
                                            wire:click="expandGap({{ $hunkIndex }})"
                                            wire:loading.attr="disabled"
                                            wire:target="expandGap"
                                            class="text-gh-link hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                        >
                                            <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="outline" class="animate-spin" />
                                            <span wire:loading.remove wire:target="expandGap">Expand {{ $hiddenCount }} hidden lines</span>
                                            <span wire:loading wire:target="expandGap">Expanding...</span>
                                        </button>
                                    @elseif($hunk['newStart'] > 1)
                                        @php $hiddenCount = $hunk['newStart'] - 1; @endphp
                                        <button
                                            wire:click="expandGap(0)"
                                            wire:loading.attr="disabled"
                                            wire:target="expandGap"
                                            class="text-gh-link hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                        >
                                            <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="outline" class="animate-spin" />
                                            <span wire:loading.remove wire:target="expandGap">Expand {{ $hiddenCount }} hidden lines</span>
                                            <span wire:loading wire:target="expandGap">Expanding...</span>
                                        </button>
                                    @else
                                        @@ -{{ $hunk['oldStart'] }} +{{ $hunk['newStart'] }} @@
                                    @endif
                                    @if($hunk['header'])
                                        <span class="text-gh-muted/60">{{ $hunk['header'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endif

                        @foreach($hunk['lines'] as $lineIndex => $line)
                            @php
                                $lineNum = $line['newLineNum'] ?? $line['oldLineNum'];
                                [$bgClass, $numBgClass, $prefix] = match($line['type']) {
                                    'add' => ['bg-gh-add-bg', 'bg-gh-add-line', '+'],
                                    'remove' => ['bg-gh-del-bg', 'bg-gh-del-line', '-'],
                                    default => ['', '', ' '],
                                };
                                $lineSide = match($line['type']) {
                                    'remove' => 'left',
                                    'add' => 'right',
                                    default => 'context',
                                };
                            @endphp
                            <tr
                                class="diff-line {{ $bgClass }}"
                                :class="isLineInSelection({{ $lineNum ?? 'null' }}) ? 'line-selected' : ''"
                                @mouseenter="onDragOver({{ $line['newLineNum'] ?? 'null' }}, {{ $line['oldLineNum'] ?? 'null' }})"
                                @if($line['newLineNum']) data-line-new="{{ $line['newLineNum'] }}" @endif
                                @if($line['oldLineNum']) data-line-old="{{ $line['oldLineNum'] }}" @endif
                            >
                                {{-- Old line number --}}
                                <td data-testid="diff-line-number" class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none cursor-pointer {{ $numBgClass }}"
                                    @if($line['oldLineNum'])
                                        @mousedown.prevent="handleLineMousedown({{ $line['oldLineNum'] }}, 'left', $event)"
                                    @endif
                                >
                                    {{ $line['oldLineNum'] ?? '' }}
                                </td>

                                {{-- New line number --}}
                                <td data-testid="diff-line-number" class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none cursor-pointer {{ $numBgClass }}"
                                    @if($line['newLineNum'])
                                        @mousedown.prevent="handleLineMousedown({{ $line['newLineNum'] }}, 'right', $event)"
                                    @endif
                                >
                                    {{ $line['newLineNum'] ?? '' }}
                                </td>

                                {{-- Prefix --}}
                                <td class="w-[1px] px-1 text-center select-none {{ $line['type'] === 'add' ? 'text-gh-green' : ($line['type'] === 'remove' ? 'text-gh-red' : 'text-gh-muted/30') }}">
                                    {{ $prefix }}
                                </td>

                                {{-- Content --}}
                                <td class="px-2 whitespace-pre-wrap break-all">{!! $line['highlightedContent'] ?? e($line['content']) !!}</td>
                            </tr>

                            {{-- Inline comment form (shows after the target line) --}}
                            @if($lineNum !== null)
                                <template x-if="showForm && formEndLine === {{ $lineNum }} && formSide !== 'file' && (@js($lineSide) === 'context' || formSide === @js($lineSide))">
                                    <tr>
                                        <td colspan="4" class="p-0">
                                            <x-comment-form save="submitComment" placeholder="Write a comment..." border-class="border-y" />
                                        </td>
                                    </tr>
                                </template>
                            @endif

                            {{-- Show saved comments inline --}}
                            @php
                                $lineComments = collect();
                                if ($lineSide === 'context') {
                                    $lineComments = collect()
                                        ->merge($commentsByLine["left:{$line['oldLineNum']}"] ?? collect())
                                        ->merge($commentsByLine["right:{$line['newLineNum']}"] ?? collect());
                                } elseif ($lineNum !== null) {
                                    $lineComments = $commentsByLine["{$lineSide}:{$lineNum}"] ?? collect();
                                }
                            @endphp
                            @foreach($lineComments as $comment)
                                <tr x-data x-show="editingCommentId !== '{{ $comment['id'] }}'">
                                    <td colspan="4" class="p-0">
                                        <x-comment-display :comment="$comment" border-class="border-y" />
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endforeach

                    @if($hasTrailingGap)
                        <tr class="bg-gh-hunk-bg">
                            <td colspan="4" class="px-4 py-1 text-gh-muted text-xs">
                                <button
                                    wire:click="expandGap({{ count($hunks) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="expandGap"
                                    class="text-gh-link hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                >
                                    <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="outline" class="animate-spin" />
                                    <span wire:loading.remove wire:target="expandGap">Expand {{ $trailingHiddenCount }} hidden lines</span>
                                    <span wire:loading wire:target="expandGap">Expanding...</span>
                                </button>
                            </td>
                        </tr>
                    @endif
                </table>
            </div>
        @endif

    </div>
</div>
