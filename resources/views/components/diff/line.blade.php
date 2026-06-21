{{-- Parent Alpine scope contract: isRowInSelection(), onDragOver(), handleLineMousedown(),
     onLineContextmenu(), toggleHeadingFold(), foldedHeadings, isLineFolded(),
     shouldShowLineCommentForm(), editingCommentId. --}}
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
    [$bgClass, $oldNumMarker, $newNumMarker, $prefix, $prefixColor] = match($type) {
        LineType::Add => ['bg-gh-add-bg', '', 'diff-num-marker diff-num-marker-add', '+', 'text-gh-green'],
        LineType::Remove => ['bg-gh-del-bg', 'diff-num-marker diff-num-marker-del', '', '-', 'text-gh-red'],
        default => ['', '', '', ' ', 'text-gh-muted/30'],
    };
    $lineSide = match($type) {
        LineType::Remove => 'left',
        LineType::Add => 'right',
        default => 'context',
    };
    $headingId = $line['headingId'] ?? null;
    $table = $line['table'] ?? null;
    $cellContent = $line['highlightedContent'] ?? e($line['content']);
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
    :class="isRowInSelection(@js($lineSide), {{ $oldNum ?? 'null' }}, {{ $newNum ?? 'null' }}) ? 'line-selected' : ''"
    @mouseenter="onDragOver({{ $newNum ?? 'null' }}, {{ $oldNum ?? 'null' }})"
    @if($hasRemote && $lineNum !== null) @contextmenu.prevent="onLineContextmenu($event, {{ $lineNum }}, '{{ $lineSide === 'left' ? 'old' : 'new' }}')" @endif
    @if($newNum) data-line-new="{{ $newNum }}" @endif
    @if($oldNum) data-line-old="{{ $oldNum }}" @endif
    @if($ancestorJs) x-show="!isLineFolded({{ $ancestorJs }})" @endif
>
    <div @if($oldNum && ! $newNum) data-testid="diff-line-number" @endif class="diff-cell diff-cell-num diff-cell-num-old {{ $bgClass }} {{ $oldNumMarker }}"
        @if($oldNum) @mousedown.prevent="handleLineMousedown({{ $oldNum }}, 'left', $event)" @endif
    >{{ $oldNum ?? '' }}</div>

    <div @if($newNum) data-testid="diff-line-number" @endif class="diff-cell diff-cell-num diff-cell-num-new {{ $bgClass }} {{ $newNumMarker }}"
        @if($newNum) @mousedown.prevent="handleLineMousedown({{ $newNum }}, 'right', $event)" @endif
    >{{ $newNum ?? '' }}</div>

    <div class="diff-cell diff-cell-prefix {{ $bgClass }} {{ $prefixColor }}">{{ $prefix }}</div>

    <div @class(['diff-cell', 'diff-cell-content', 'diff-cell-table' => $table !== null, $bgClass])>@if($table !== null)<x-diff.md-table :table="$table" />@elseif($headingId !== null)<button
            type="button"
            data-testid="heading-fold-toggle"
            data-heading-id="{{ $headingId }}"
            @click.stop="toggleHeadingFold({{ $headingId }})"
            :aria-label="foldedHeadings[{{ $headingId }}] ? 'Expand section' : 'Collapse section'"
            :aria-expanded="!foldedHeadings[{{ $headingId }}]"
            class="inline-flex align-middle -my-0.5 mr-1 size-4 items-center justify-center text-gh-muted/60 hover:text-gh-text"
        ><flux:icon icon="chevron-down" variant="outline" class="!size-3" x-show="!foldedHeadings[{{ $headingId }}]" /><flux:icon icon="chevron-right" variant="outline" class="!size-3" x-show="foldedHeadings[{{ $headingId }}]" x-cloak /></button>{!! $cellContent !!}@else{!! $cellContent !!}@endif</div>

    @if($type === LineType::Context)
        {{-- Mirror cell: shown only in split mode (CSS hides in unified). --}}
        <div @class(['diff-cell', 'diff-cell-content', 'diff-cell-content-mirror', 'diff-cell-table' => $table !== null, $bgClass]) aria-hidden="true">@if($table !== null)<x-diff.md-table :table="$table" />@else{!! $cellContent !!}@endif</div>
    @endif
</div>

@if($lineNum !== null)
    <template x-if="shouldShowLineCommentForm(@js($lineSide), {{ $oldNum ?? 'null' }}, {{ $newNum ?? 'null' }})">
        <div class="diff-fullspan comment-open" @if($ancestorJs) x-show="!isLineFolded({{ $ancestorJs }})" @endif>
            <x-comment-form save="submitComment" placeholder="Write a comment..." border-class="border-y" />
        </div>
    </template>
@endif

@foreach($lineComments as $comment)
    <div id="comment-{{ $comment['id'] }}" class="diff-fullspan" x-data x-show="editingCommentId !== '{{ $comment['id'] }}'@if($ancestorJs) && !isLineFolded({{ $ancestorJs }})@endif">
        <x-comment-display :comment="$comment" border-class="border-y" />
    </div>
@endforeach
