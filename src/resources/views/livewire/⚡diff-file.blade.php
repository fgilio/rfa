<?php

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

    private ?DiffTarget $cachedTarget = null;

    public function hydrate(): void
    {
        $this->diffData = Cache::get($this->diffCacheKey());
    }

    /** @param array<int, array<string, mixed>> $comments */
    public function updateComments(array $comments): void
    {
        $this->fileComments = $comments;
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
            theme: $this->resolveTheme(),
        );
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
            theme: $this->resolveTheme(),
        );
    }

    public function expandGap(int $hunkIndex): void
    {
        if ($this->diffData === null || empty($this->diffData['hunks'])) {
            return;
        }

        $hunks = $this->diffData['hunks'];

        // Determine the new-line range for this gap
        if ($hunkIndex === 0) {
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
            theme: $this->resolveTheme(),
        );

        if (empty($fullDiff['hunks'])) {
            return;
        }

        // Extract gap lines from the full diff's single hunk by newLineNum
        $gapLines = [];
        foreach ($fullDiff['hunks'][0]['lines'] as $line) {
            $num = $line['newLineNum'] ?? null;
            if ($num !== null && $num >= $gapNewStart && $num <= $gapNewEnd && $line['type'] === 'context') {
                $gapLines[] = $line;
            }
        }

        if (empty($gapLines)) {
            return;
        }

        $gapSize = count($gapLines);

        if ($hunkIndex === 0) {
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

        // Update cache with expanded state
        Cache::put($this->diffCacheKey(), $this->diffData, now()->addHours($this->buildDiffTarget()->cacheTtlHours()));
    }

    private function buildDiffTarget(): DiffTarget
    {
        return $this->cachedTarget ??= DiffTarget::fromRefs($this->diffFrom, $this->diffTo);
    }

    public function reloadForTheme(): void
    {
        if ($this->diffData === null) {
            return;
        }

        $cacheKey = $this->diffCacheKey();
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $this->diffData = $cached;

            return;
        }

        $this->diffData = app(LoadFileDiffAction::class)->handle(
            $this->repoPath,
            $this->file['path'],
            $this->file['isUntracked'] ?? false,
            cacheKey: $cacheKey,
            target: $this->buildDiffTarget(),
            theme: $this->resolveTheme(),
        );
    }

    private function resolveTheme(): string
    {
        return request()->cookie('rfa_theme') === 'dark' ? 'dark' : 'light';
    }

    private function diffCacheKey(): string
    {
        $projectKey = $this->projectId > 0 ? $this->projectId : $this->repoPath;

        return DiffCacheKey::for($projectKey, $this->file['id'], $this->buildDiffTarget()->contextKey(), $this->resolveTheme());
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
    @theme-changed.window="setTimeout(() => $wire.reloadForTheme(), {{ $loadDelay }})"
    @collapse-all-files.window="collapsed = true"
    @expand-all-files.window="collapsed = false"
    @expand-file.window="if ($event.detail.id === fileId) collapsed = false"
    class="group"
>
    {{-- File header --}}
    <div data-testid="file-header" class="sticky top-[var(--header-h)] z-10 bg-gh-surface/80 backdrop-blur-sm border-b border-gh-border px-5 py-2.5 flex items-center gap-2.5">
        <button :aria-label="collapsed ? 'Expand file' : 'Collapse file'" @click="if ($event.altKey) { $dispatch(collapsed ? 'expand-all-files' : 'collapse-all-files') } else { collapsed = !collapsed }" class="text-gh-muted hover:text-gh-text transition-colors">
            <flux:icon icon="chevron-down" variant="outline" x-show="!collapsed" />
            <flux:icon icon="chevron-right" variant="outline" x-show="collapsed" x-cloak />
        </button>

        <span class="font-mono text-sm truncate cursor-pointer" @click="if ($event.altKey) { $dispatch(collapsed ? 'expand-all-files' : 'collapse-all-files') } else { collapsed = !collapsed }">
            @if($file['oldPath'])
                <span class="text-gh-muted">{{ $file['oldPath'] }} &rarr;</span>
            @endif
            {{ $file['path'] }}
        </span>

        <flux:tooltip content="Copy file name">
            <flux:button
                icon="square-2-stack"
                icon:variant="outline"
                variant="ghost"
                size="sm"
                @click="$dispatch('copy-to-clipboard', { text: filePath })"
            />
        </flux:tooltip>

        <span class="ml-auto flex items-center gap-2.5 text-xs shrink-0 font-mono">
            @if($file['additions'] > 0)
                <span class="text-gh-green">+{{ $file['additions'] }}</span>
            @endif
            @if($file['deletions'] > 0)
                <span class="text-gh-red">-{{ $file['deletions'] }}</span>
            @endif
            <flux:checkbox x-model="viewed" @change="onViewedChange()" label="Viewed" class="text-xs" />
            <flux:tooltip content="Add file comment">
                <flux:button
                    icon="chat-bubble-left"
                    icon:variant="outline"
                    variant="ghost"
                    size="sm"
                    aria-label="Add file comment"
                    @click="openFileComment()"
                    class="ml-2"
                />
            </flux:tooltip>
        </span>
    </div>

    {{-- File body --}}
    <div x-show="!collapsed" x-collapse.duration.150ms>
        @if($file['isBinary'] && !($file['isImage'] ?? false))
            <div class="px-4 py-8 text-center">
                <flux:text variant="subtle" size="sm">Binary file not shown</flux:text>
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
                $hasGaps = count($hunks) > 1 || (count($hunks) === 1 && $hunks[0]['newStart'] > 1);
            @endphp
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
                                <tr x-data @if($comment['isDraft'] ?? false) x-show="editingCommentId !== '{{ $comment['id'] }}'" @endif>
                                    <td colspan="4" class="p-0">
                                        <x-comment-display :comment="$comment" border-class="border-y" />
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endforeach
                </table>
            </div>
        @endif

        {{-- File-level comment form --}}
        <template x-if="showForm && formSide === 'file'">
            <x-comment-form save="submitComment" placeholder="File comment..." border-class="border-t" />
        </template>

        {{-- File-level saved comments --}}
        @foreach($fileComments as $comment)
            @if($comment['side'] === 'file')
                <div x-data @if($comment['isDraft'] ?? false) x-show="editingCommentId !== '{{ $comment['id'] }}'" @endif>
                    <x-comment-display :comment="$comment" border-class="border-t" />
                </div>
            @endif
        @endforeach
    </div>
</div>
