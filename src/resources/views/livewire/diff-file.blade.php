{{-- Single file diff rendering --}}
<div
    x-data="{
        collapsed: false,
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
            $wire.call('addComment', '{{ $file['id'] }}', this.draftSide, this.draftLine, this.draftEndLine, this.draftBody);
            this.cancelDraft();
        },

        saveFileComment() {
            if (this.draftBody.trim() === '') return;
            $wire.call('addComment', '{{ $file['id'] }}', 'file', null, null, this.draftBody);
            this.cancelDraft();
        },

        isLineInDraft(lineNum) {
            if (!this.showDraft || this.draftLine === null) return false;
            return lineNum >= this.draftLine && lineNum <= (this.draftEndLine ?? this.draftLine);
        }
    }"
    class="group"
>
    {{-- File header --}}
    <div class="sticky top-[53px] z-10 bg-gh-surface border-b border-gh-border px-4 py-2 flex items-center gap-2">
        <button @click="collapsed = !collapsed" class="text-gh-muted hover:text-gh-text transition-colors">
            <svg x-show="!collapsed" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            <svg x-show="collapsed" x-cloak class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>

        <span class="text-xs font-mono text-gh-text truncate">
            @if($file['oldPath'])
                <span class="text-gh-muted">{{ $file['oldPath'] }} &rarr;</span>
            @endif
            {{ $file['path'] }}
        </span>

        <span class="ml-auto flex items-center gap-2 text-xs shrink-0">
            @if($file['additions'] > 0)
                <span class="text-green-400">+{{ $file['additions'] }}</span>
            @endif
            @if($file['deletions'] > 0)
                <span class="text-red-400">-{{ $file['deletions'] }}</span>
            @endif
            <button
                @click="draftLine = null; draftEndLine = null; draftSide = 'file'; showDraft = true; $nextTick(() => $refs.commentInput?.focus())"
                class="text-gh-muted hover:text-gh-accent transition-colors ml-2"
                title="Add file comment"
            >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
            </button>
        </span>
    </div>

    {{-- File body --}}
    <div x-show="!collapsed" x-collapse.duration.150ms>
        @if($file['isBinary'])
            <div class="px-4 py-8 text-center text-gh-muted text-xs">
                Binary file not shown
            </div>
        @elseif(empty($file['hunks']))
            <div class="px-4 py-8 text-center text-gh-muted text-xs">
                File renamed (no content changes)
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full border-collapse font-mono text-xs leading-5">
                    @foreach($file['hunks'] as $hunkIndex => $hunk)
                        {{-- Hunk header --}}
                        @if($hunkIndex > 0 || $hunk['header'] !== '')
                            <tr class="bg-blue-900/10">
                                <td colspan="4" class="px-4 py-1 text-gh-muted text-xs">
                                    @@ -{{ $hunk['oldStart'] }} +{{ $hunk['newStart'] }} @@
                                    @if($hunk['header'])
                                        <span class="text-gh-muted/60">{{ $hunk['header'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endif

                        @foreach($hunk['lines'] as $lineIndex => $line)
                            @php
                                $lineNum = $line['newLineNum'] ?? $line['oldLineNum'];
                                $bgClass = match($line['type']) {
                                    'add' => 'bg-gh-add-bg',
                                    'remove' => 'bg-gh-del-bg',
                                    default => '',
                                };
                                $numBgClass = match($line['type']) {
                                    'add' => 'bg-gh-add-line',
                                    'remove' => 'bg-gh-del-line',
                                    default => '',
                                };
                                $prefix = match($line['type']) {
                                    'add' => '+',
                                    'remove' => '-',
                                    default => ' ',
                                };
                            @endphp
                            <tr
                                class="diff-line {{ $bgClass }}"
                                :class="isLineInDraft({{ $lineNum ?? 'null' }}) ? 'line-selected' : ''"
                            >
                                {{-- Old line number --}}
                                <td class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none {{ $numBgClass }}"
                                    @if($line['oldLineNum'])
                                        @click="selectLine({{ $line['oldLineNum'] }}, 'left', $event)"
                                    @endif
                                >
                                    {{ $line['oldLineNum'] ?? '' }}
                                </td>

                                {{-- New line number --}}
                                <td class="diff-line-num w-[1px] px-2 text-right text-gh-muted/50 select-none {{ $numBgClass }}"
                                    @if($line['newLineNum'])
                                        @click="selectLine({{ $line['newLineNum'] }}, 'right', $event)"
                                    @endif
                                >
                                    {{ $line['newLineNum'] ?? '' }}
                                </td>

                                {{-- Prefix --}}
                                <td class="w-[1px] px-1 text-center select-none {{ $line['type'] === 'add' ? 'text-green-400' : ($line['type'] === 'remove' ? 'text-red-400' : 'text-gh-muted/30') }}">
                                    {{ $prefix }}
                                </td>

                                {{-- Content --}}
                                <td class="px-2 whitespace-pre-wrap break-all">{{ $line['content'] }}</td>
                            </tr>

                            {{-- Inline comment form (shows after the target line) --}}
                            @if($lineNum !== null)
                                <template x-if="showDraft && draftEndLine === {{ $lineNum }} && draftSide !== 'file'">
                                    <tr>
                                        <td colspan="4" class="p-0">
                                            <div class="bg-gh-surface border-y border-gh-border p-3">
                                                <textarea
                                                    x-ref="commentInput"
                                                    x-model="draftBody"
                                                    @keydown.meta.enter="saveDraft()"
                                                    @keydown.escape="cancelDraft()"
                                                    class="w-full bg-gh-bg border border-gh-border rounded px-3 py-2 text-gh-text text-xs font-mono resize-y min-h-[60px] focus:outline-none focus:border-gh-accent"
                                                    placeholder="Write a comment... (Cmd+Enter to save, Esc to cancel)"
                                                ></textarea>
                                                <div class="flex justify-end gap-2 mt-2">
                                                    <button @click="cancelDraft()" class="px-3 py-1 text-xs text-gh-muted hover:text-gh-text border border-gh-border rounded transition-colors">Cancel</button>
                                                    <button @click="saveDraft()" class="px-3 py-1 text-xs text-white bg-green-700 hover:bg-green-600 rounded transition-colors">Save</button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            @endif

                            {{-- Show saved comments inline --}}
                            @foreach($this->getCommentsForFile($file['id']) as $comment)
                                @if($comment['endLine'] === ($lineNum ?? -1) && $comment['side'] !== 'file')
                                    <tr>
                                        <td colspan="4" class="p-0">
                                            <div class="comment-indicator bg-gh-surface/80 border-y border-gh-border px-4 py-2">
                                                <div class="flex items-start justify-between gap-2">
                                                    <div class="text-xs text-gh-text whitespace-pre-wrap">{{ $comment['body'] }}</div>
                                                    <button
                                                        wire:click="deleteComment('{{ $comment['id'] }}')"
                                                        class="text-gh-muted hover:text-red-400 shrink-0 transition-colors"
                                                        title="Delete comment"
                                                    >
                                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                                @if($comment['startLine'] !== $comment['endLine'] && $comment['endLine'] !== null)
                                                    <div class="text-[10px] text-gh-muted mt-1">Lines {{ $comment['startLine'] }}-{{ $comment['endLine'] }}</div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        @endforeach
                    @endforeach
                </table>
            </div>
        @endif

        {{-- File-level comment form --}}
        <template x-if="showDraft && draftSide === 'file'">
            <div class="bg-gh-surface border-t border-gh-border p-3">
                <textarea
                    x-ref="commentInput"
                    x-model="draftBody"
                    @keydown.meta.enter="saveFileComment()"
                    @keydown.escape="cancelDraft()"
                    class="w-full bg-gh-bg border border-gh-border rounded px-3 py-2 text-gh-text text-xs font-mono resize-y min-h-[60px] focus:outline-none focus:border-gh-accent"
                    placeholder="File comment... (Cmd+Enter to save, Esc to cancel)"
                ></textarea>
                <div class="flex justify-end gap-2 mt-2">
                    <button @click="cancelDraft()" class="px-3 py-1 text-xs text-gh-muted hover:text-gh-text border border-gh-border rounded transition-colors">Cancel</button>
                    <button @click="saveFileComment()" class="px-3 py-1 text-xs text-white bg-green-700 hover:bg-green-600 rounded transition-colors">Save</button>
                </div>
            </div>
        </template>

        {{-- File-level saved comments --}}
        @foreach($this->getCommentsForFile($file['id']) as $comment)
            @if($comment['side'] === 'file')
                <div class="comment-indicator bg-gh-surface/80 border-t border-gh-border px-4 py-2">
                    <div class="flex items-start justify-between gap-2">
                        <div class="text-xs text-gh-text whitespace-pre-wrap">{{ $comment['body'] }}</div>
                        <button
                            wire:click="deleteComment('{{ $comment['id'] }}')"
                            class="text-gh-muted hover:text-red-400 shrink-0 transition-colors"
                            title="Delete comment"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</div>
