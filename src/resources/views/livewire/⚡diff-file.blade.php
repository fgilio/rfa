<?php

use App\Actions\LoadFileDiffAction;
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

    public bool $isViewed = false;

    /** @var array<int, array<string, mixed>> */
    public array $fileComments = [];

    /** @var array<string, mixed>|null */
    protected ?array $diffData = null;

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
        Cache::put($this->diffCacheKey(), $this->diffData, now()->addHours(config('rfa.cache_ttl_hours', 24)));
    }

    private function diffCacheKey(): string
    {
        $key = $this->projectId > 0 ? $this->projectId : $this->repoPath;

        return DiffCacheKey::for($key, $this->file['id']);
    }

    public function render(): \Illuminate\Contracts\View\View
    {
        return $this->view(['diffData' => $this->diffData]);
    }
};
?>

{{-- Single file diff rendering --}}
<div
    x-data="{
        fileId: {{ Js::from($file['id']) }},
        filePath: {{ Js::from($file['path']) }},
        collapsed: @js($isViewed ?? false),
        viewed: @js($isViewed ?? false),
        draftLine: null,
        draftEndLine: null,
        draftSide: 'right',
        draftBody: '',
        lastClickedLine: null,
        showDraft: false,

        selectLine(lineNum, side, event) {
            if (event.shiftKey && this.lastClickedLine !== null) {
                this.draftLine = Math.min(this.lastClickedLine, lineNum);
                this.draftEndLine = Math.max(this.lastClickedLine, lineNum);
            } else {
                this.draftLine = lineNum;
                this.draftEndLine = lineNum;
                this.lastClickedLine = lineNum;
            }
            this.draftSide = side;
            this.showDraft = true;
            this.$nextTick(() => {
                this.$refs.commentInput?.focus();
            });
        },

        cancelDraft() {
            this.showDraft = false;
            this.draftBody = '';
            this.draftLine = null;
            this.draftEndLine = null;
        },

        saveDraft() {
            if (this.draftBody.trim() === '') return;
            $wire.dispatch('add-comment', { fileId: this.fileId, side: this.draftSide, startLine: this.draftLine, endLine: this.draftEndLine, body: this.draftBody });
            this.cancelDraft();
        },

        saveFileComment() {
            if (this.draftBody.trim() === '') return;
            $wire.dispatch('add-comment', { fileId: this.fileId, side: 'file', startLine: null, endLine: null, body: this.draftBody });
            this.cancelDraft();
        },

        isLineInDraft(lineNum) {
            if (!this.showDraft || this.draftLine === null) return false;
            return lineNum >= this.draftLine && lineNum <= (this.draftEndLine ?? this.draftLine);
        },

        onViewedChange() {
            this.collapsed = this.viewed;
            $dispatch('file-viewed-changed', { id: this.fileId, viewed: this.viewed });
            $wire.dispatch('toggle-viewed', { filePath: this.filePath });
        }
    }"
    @comment-updated.window="if ($event.detail.fileId === fileId) $wire.updateComments($event.detail.comments)"
    @collapse-all-files.window="collapsed = true"
    @expand-all-files.window="collapsed = false"
    @expand-file.window="if ($event.detail.id === fileId) collapsed = false"
    class="group"
>
    {{-- File header --}}
    <div data-testid="file-header" class="sticky top-[var(--header-h)] z-10 bg-gh-surface border-b border-gh-border px-4 py-2 flex items-center gap-2">
        <button :aria-label="collapsed ? 'Expand file' : 'Collapse file'" @click="if ($event.altKey) { $dispatch(collapsed ? 'expand-all-files' : 'collapse-all-files') } else { collapsed = !collapsed }" class="text-gh-muted hover:text-gh-text transition-colors">
            <flux:icon icon="chevron-down" variant="micro" x-show="!collapsed" />
            <flux:icon icon="chevron-right" variant="micro" x-show="collapsed" x-cloak />
        </button>

        <flux:text size="sm" inline class="font-mono truncate">
            @if($file['oldPath'])
                <flux:text variant="subtle" size="sm" inline>{{ $file['oldPath'] }} &rarr;</flux:text>
            @endif
            {{ $file['path'] }}
        </flux:text>

        <flux:tooltip content="Copy file name">
            <flux:button
                icon="square-2-stack"
                variant="ghost"
                size="sm"
                @click="$dispatch('copy-to-clipboard', { text: filePath })"
            />
        </flux:tooltip>

        <span class="ml-auto flex items-center gap-2 text-xs shrink-0">
            @if($file['additions'] > 0)
                <flux:badge color="green" size="sm">+{{ $file['additions'] }}</flux:badge>
            @endif
            @if($file['deletions'] > 0)
                <flux:badge color="red" size="sm">-{{ $file['deletions'] }}</flux:badge>
            @endif
            <flux:checkbox x-model="viewed" @change="onViewedChange()" label="Viewed" class="text-xs" />
            <flux:tooltip content="Add file comment">
                <flux:button
                    icon="chat-bubble-left"
                    variant="ghost"
                    size="sm"
                    aria-label="Add file comment"
                    @click="draftLine = null; draftEndLine = null; draftSide = 'file'; showDraft = true; $nextTick(() => $refs.commentInput?.focus())"
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
            @endphp
            <div class="px-4 py-6 flex items-start justify-center gap-6">
                @if($hasBeforeImage)
                    <div class="flex flex-col items-center gap-2 {{ $hasAfterImage ? 'max-w-[50%]' : '' }}">
                        <flux:badge color="red" size="sm">{{ $status === 'deleted' ? 'Deleted' : 'Before' }}</flux:badge>
                        <div class="border border-gh-border rounded-lg p-1" style="background: repeating-conic-gradient(rgb(128 128 128 / 0.15) 0% 25%, transparent 0% 50%) 50% / 16px 16px;">
                            <img
                                src="/api/image/{{ $projectId }}/head/{{ $beforePath }}"
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
                                src="/api/image/{{ $projectId }}/working/{{ $file['path'] }}"
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
                    <flux:icon icon="arrow-path" variant="micro" class="animate-spin inline-block text-gh-muted mr-1" />
                    <flux:text variant="subtle" size="sm" inline>Loading diff...</flux:text>
                </div>
                <div wire:loading.remove wire:target="loadFileDiff">
                    <flux:text variant="subtle" size="sm">Waiting to load...</flux:text>
                </div>
            </div>
        @elseif($diffData['tooLarge'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="micro" class="inline-block text-gh-muted mr-1" />
                <flux:text variant="subtle" size="sm" inline>File diff too large to display</flux:text>
            </div>
        @elseif($diffData['error'] ?? false)
            <div class="px-4 py-8 text-center">
                <flux:icon icon="exclamation-triangle" variant="micro" class="inline-block text-red-400 mr-1" />
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
                <table class="w-full border-collapse font-mono text-xs leading-5">
                    @if($hasGaps)
                        <tr class="bg-gh-hunk-bg">
                            <td colspan="4" class="px-4 py-1 text-center">
                                <button
                                    wire:click="expandContext"
                                    wire:loading.attr="disabled"
                                    wire:target="expandContext"
                                    class="text-gh-accent text-xs hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                >
                                    <flux:icon wire:loading wire:target="expandContext" icon="arrow-path" variant="micro" class="animate-spin" />
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
                                            class="text-gh-accent hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                        >
                                            <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="micro" class="animate-spin" />
                                            <span wire:loading.remove wire:target="expandGap">Expand {{ $hiddenCount }} hidden lines</span>
                                            <span wire:loading wire:target="expandGap">Expanding...</span>
                                        </button>
                                    @elseif($hunk['newStart'] > 1)
                                        @php $hiddenCount = $hunk['newStart'] - 1; @endphp
                                        <button
                                            wire:click="expandGap(0)"
                                            wire:loading.attr="disabled"
                                            wire:target="expandGap"
                                            class="text-gh-accent hover:underline inline-flex items-center gap-1 disabled:opacity-50"
                                        >
                                            <flux:icon wire:loading wire:target="expandGap" icon="arrow-path" variant="micro" class="animate-spin" />
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
                                :class="isLineInDraft({{ $lineNum ?? 'null' }}) ? 'line-selected' : ''"
                            >
                                {{-- Old line number --}}
                                <td data-testid="diff-line-number" class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none {{ $numBgClass }}"
                                    @if($line['oldLineNum'])
                                        @click="selectLine({{ $line['oldLineNum'] }}, 'left', $event)"
                                    @endif
                                >
                                    {{ $line['oldLineNum'] ?? '' }}
                                </td>

                                {{-- New line number --}}
                                <td data-testid="diff-line-number" class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none {{ $numBgClass }}"
                                    @if($line['newLineNum'])
                                        @click="selectLine({{ $line['newLineNum'] }}, 'right', $event)"
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
                                <template x-if="showDraft && draftEndLine === {{ $lineNum }} && draftSide !== 'file'">
                                    <tr>
                                        <td colspan="4" class="p-0">
                                            <flux:card size="sm" class="!rounded-none border-y border-gh-border">
                                                <flux:textarea
                                                    x-ref="commentInput"
                                                    x-model="draftBody"
                                                    @keydown.meta.enter="saveDraft()"
                                                    @keydown.ctrl.enter="saveDraft()"
                                                    @keydown.escape="cancelDraft()"
                                                    placeholder="Write a comment... (Cmd/Ctrl+Enter to save, Esc to cancel)"
                                                    rows="2"
                                                    resize="vertical"
                                                    class="font-mono text-xs"
                                                />
                                                <div class="flex justify-end gap-2 mt-2">
                                                    <flux:button variant="ghost" size="sm" @click="cancelDraft()">Cancel</flux:button>
                                                    <flux:button variant="primary" size="sm" color="green" @click="saveDraft()">Save</flux:button>
                                                </div>
                                            </flux:card>
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
                                <tr>
                                    <td colspan="4" class="p-0">
                                        <div class="comment-indicator bg-gh-surface/80 border-y border-gh-border px-4 py-2">
                                            <div class="flex items-start justify-between gap-2">
                                                <flux:text size="sm" class="whitespace-pre-wrap">{{ $comment['body'] }}</flux:text>
                                                <flux:tooltip content="Delete comment">
                                                    <flux:button
                                                        icon="x-mark"
                                                        variant="ghost"
                                                        size="xs"
                                                        aria-label="Delete comment"
                                                        @click="$wire.dispatch('delete-comment', { commentId: '{{ $comment['id'] }}' })"
                                                        class="shrink-0 hover:!text-red-400"
                                                    />
                                                </flux:tooltip>
                                            </div>
                                            @if($comment['startLine'] !== $comment['endLine'] && $comment['endLine'] !== null)
                                                <flux:text variant="subtle" size="sm" class="!text-[10px] mt-1">Lines {{ $comment['startLine'] }}-{{ $comment['endLine'] }}</flux:text>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    @endforeach
                </table>
            </div>
        @endif

        {{-- File-level comment form --}}
        <template x-if="showDraft && draftSide === 'file'">
            <flux:card size="sm" class="!rounded-none border-t border-gh-border">
                <flux:textarea
                    x-ref="commentInput"
                    x-model="draftBody"
                    @keydown.meta.enter="saveFileComment()"
                    @keydown.ctrl.enter="saveFileComment()"
                    @keydown.escape="cancelDraft()"
                    placeholder="File comment... (Cmd/Ctrl+Enter to save, Esc to cancel)"
                    rows="2"
                    resize="vertical"
                    class="font-mono text-xs"
                />
                <div class="flex justify-end gap-2 mt-2">
                    <flux:button variant="ghost" size="sm" @click="cancelDraft()">Cancel</flux:button>
                    <flux:button variant="primary" size="sm" color="green" @click="saveFileComment()">Save</flux:button>
                </div>
            </flux:card>
        </template>

        {{-- File-level saved comments --}}
        @foreach($fileComments as $comment)
            @if($comment['side'] === 'file')
                <div class="comment-indicator bg-gh-surface/80 border-t border-gh-border px-4 py-2">
                    <div class="flex items-start justify-between gap-2">
                        <flux:text size="sm" class="whitespace-pre-wrap">{{ $comment['body'] }}</flux:text>
                        <flux:tooltip content="Delete comment">
                            <flux:button
                                icon="x-mark"
                                variant="ghost"
                                size="xs"
                                aria-label="Delete comment"
                                @click="$wire.dispatch('delete-comment', { commentId: '{{ $comment['id'] }}' })"
                                class="shrink-0 hover:!text-red-400"
                            />
                        </flux:tooltip>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
