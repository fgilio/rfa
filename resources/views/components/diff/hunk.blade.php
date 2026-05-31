@props([
    'hunk',
    'hunkIndex',
    'prevHunk' => null,
    'hasRemote' => false,
    'commentsByLine',
])

{{-- Each hunk is its own subgrid so split-mode `grid-auto-flow: dense` pairs
     remove+add only within this hunk — never across hunk boundaries. --}}
<div class="diff-hunk">
    {{-- Gap with expand controls (or hunk section-context label when no preceding gap). --}}
    @if($hunkIndex > 0 || $hunk['newStart'] > 1)
        @php
            $hiddenCount = $hunkIndex > 0
                ? $hunk['newStart'] - ($prevHunk['newStart'] + $prevHunk['newCount'])
                : $hunk['newStart'] - 1;
        @endphp
        <x-diff.expand-control wire:key="expand-gap-{{ $hunkIndex }}-{{ $hiddenCount }}">
            <x-tiered-expand-gap :hunk-index="$hunkIndex" :hidden-count="$hiddenCount" />
        </x-diff.expand-control>
    @elseif($hunk['header'] !== '')
        <x-diff.expand-control :icon="false" align="start">
            <span class="text-gh-muted/70">{{ $hunk['header'] }}</span>
        </x-diff.expand-control>
    @endif

    @foreach($hunk['lines'] as $line)
        <x-diff.line :line="$line" :has-remote="$hasRemote" :comments-by-line="$commentsByLine" />
    @endforeach
</div>
