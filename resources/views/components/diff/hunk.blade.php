@props([
    'hunk',
    'hunkIndex',
    'prevHunk' => null,
    'hasRemote' => false,
    'commentsByLine',
])

{{-- Gap with expand controls (or hunk header when no preceding gap). --}}
@if($hunkIndex > 0 || $hunk['newStart'] > 1)
    <div class="diff-fullspan bg-gh-hunk-bg py-1.5 text-center text-xs border-y border-dashed border-gh-border/20">
        @php
            $hiddenCount = $hunkIndex > 0
                ? $hunk['newStart'] - ($prevHunk['newStart'] + $prevHunk['newCount'])
                : $hunk['newStart'] - 1;
        @endphp
        <x-tiered-expand-gap :hunk-index="$hunkIndex" :hidden-count="$hiddenCount" />
    </div>
@elseif($hunk['header'] !== '')
    <div class="diff-fullspan bg-gh-hunk-bg px-4 py-1 text-gh-muted text-xs">
        @@ -{{ $hunk['oldStart'] }} +{{ $hunk['newStart'] }} @@
        <span class="text-gh-muted/60">{{ $hunk['header'] }}</span>
    </div>
@endif

@foreach($hunk['lines'] as $line)
    <x-diff.line :line="$line" :has-remote="$hasRemote" :comments-by-line="$commentsByLine" />
@endforeach
