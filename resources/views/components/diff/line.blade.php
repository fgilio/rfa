{{-- Parent Alpine scope contract: isLineInSelection(), onDragOver(), handleLineMousedown(),
     onLineContextmenu(), toggleHeadingFold(), foldedHeadings, isLineFolded(),
     showForm, formEndLine, formSide, editingCommentId. --}}
@props([
    'line',
    'hasRemote' => false,
    'commentsByLine' => [],
])

@php
    use App\Enums\LineType;

    $type = $line['type'];
    $oldNum = $line['oldLineNum'] ?? null;
    $newNum = $line['newLineNum'] ?? null;
    $lineNum = $newNum ?? $oldNum;
    [$bgClass, $oldNumBgClass, $newNumBgClass, $prefix, $prefixColor] = match($type) {
        LineType::Add => ['bg-gh-add-bg', '', 'bg-gh-add-line', '+', 'text-gh-green'],
        LineType::Remove => ['bg-gh-del-bg', 'bg-gh-del-line', '', '-', 'text-gh-red'],
        default => ['', '', '', ' ', 'text-gh-muted/30'],
    };
    $lineSide = match($type) {
        LineType::Remove => 'left',
        LineType::Add => 'right',
        default => 'context',
    };
    $headingId = $line['headingId'] ?? null;
    $headingAncestors = $line['headingAncestors'] ?? [];
    $ancestorJs = $headingAncestors === [] ? null : json_encode($headingAncestors);

    $lineComments = match($type) {
        LineType::Remove => $commentsByLine["left:{$oldNum}"] ?? [],
        LineType::Add => $commentsByLine["right:{$newNum}"] ?? [],
        default => [
            ...($commentsByLine["left:{$oldNum}"] ?? []),
            ...($commentsByLine["right:{$newNum}"] ?? []),
        ],
    };
@endphp

<div
    class="diff-line"
    data-type="{{ $type->value }}"
    :class="isLineSideInSelection({{ $lineNum ?? 'null' }}, @js($lineSide)) ? 'line-selected' : ''"
    @mouseenter="onDragOver({{ $newNum ?? 'null' }}, {{ $oldNum ?? 'null' }})"
    @if($hasRemote && $lineNum !== null) @contextmenu.prevent="onLineContextmenu($event, {{ $lineNum }}, '{{ $lineSide === 'left' ? 'old' : 'new' }}')" @endif
    @if($newNum) data-line-new="{{ $newNum }}" @endif
    @if($oldNum) data-line-old="{{ $oldNum }}" @endif
    @if($ancestorJs) x-show="!isLineFolded({{ $ancestorJs }})" @endif
>
    <div data-testid="diff-line-number" class="diff-cell diff-cell-num diff-cell-num-old {{ $bgClass }} {{ $oldNumBgClass }}"
        @if($oldNum) @mousedown.prevent="handleLineMousedown({{ $oldNum }}, 'left', $event)" @endif
    >{{ $oldNum ?? '' }}</div>

    <div data-testid="diff-line-number" class="diff-cell diff-cell-num diff-cell-num-new {{ $bgClass }} {{ $newNumBgClass }}"
        @if($newNum) @mousedown.prevent="handleLineMousedown({{ $newNum }}, 'right', $event)" @endif
    >{{ $newNum ?? '' }}</div>

    <div class="diff-cell diff-cell-prefix {{ $bgClass }} {{ $prefixColor }}">{{ $prefix }}</div>

    <div class="diff-cell diff-cell-content {{ $bgClass }}">@if($headingId !== null)<button
            type="button"
            data-testid="heading-fold-toggle"
            data-heading-id="{{ $headingId }}"
            @click.stop="toggleHeadingFold({{ $headingId }})"
            :aria-label="foldedHeadings[{{ $headingId }}] ? 'Expand section' : 'Collapse section'"
            :aria-expanded="!foldedHeadings[{{ $headingId }}]"
            class="inline-flex align-middle -my-0.5 mr-1 size-4 items-center justify-center text-gh-muted/60 hover:text-gh-text"
        ><flux:icon icon="chevron-down" variant="outline" class="!size-3" x-show="!foldedHeadings[{{ $headingId }}]" /><flux:icon icon="chevron-right" variant="outline" class="!size-3" x-show="foldedHeadings[{{ $headingId }}]" x-cloak /></button>@endif{!! $line['highlightedContent'] ?? e($line['content']) !!}</div>

    @if($type === LineType::Context)
        {{-- Mirror cell: shown only in split mode (CSS hides in unified). --}}
        <div class="diff-cell diff-cell-content diff-cell-content-mirror {{ $bgClass }}" aria-hidden="true">{!! $line['highlightedContent'] ?? e($line['content']) !!}</div>
    @endif
</div>

@if($lineNum !== null)
    <template x-if="showForm && formEndLine === {{ $lineNum }} && formSide !== 'file' && (@js($lineSide) === 'context' || formSide === @js($lineSide))">
        <div class="diff-fullspan" @if($ancestorJs) x-show="!isLineFolded({{ $ancestorJs }})" @endif>
            <x-comment-form save="submitComment" placeholder="Write a comment..." border-class="border-y" />
        </div>
    </template>
@endif

@foreach($lineComments as $comment)
    <div class="diff-fullspan" x-data x-show="editingCommentId !== '{{ $comment['id'] }}'@if($ancestorJs) && !isLineFolded({{ $ancestorJs }})@endif">
        <x-comment-display :comment="$comment" border-class="border-y" />
    </div>
@endforeach
