@props([
    'commitInfo',
    'projectSlug',
])

{{-- Sticky chrome bar shown in commit/range view: short hash, message,
     author, plus prev/next/close navigation buttons. State + scoped
     navigation — the actions only navigate within the commit context the
     bar describes. --}}
<div data-testid="commit-context-bar" class="sticky top-[var(--header-h)] z-40 bg-gh-surface border-b border-gh-border px-5 py-2.5 flex items-center gap-3 text-xs" style="--commit-bar-h: 40px;">
    <flux:icon icon="code-bracket" variant="outline" class="text-gh-muted shrink-0" />
    <span class="font-mono text-xs text-gh-muted shrink-0 px-1.5 py-0.5 rounded border border-gh-border">{{ $commitInfo['shortHash'] }}</span>
    <span class="text-gh-text truncate font-medium">{{ $commitInfo['message'] }}</span>
    <span class="text-gh-muted shrink-0">{{ $commitInfo['author'] }}</span>
    <div class="ml-auto flex items-center gap-1 shrink-0">
        @if($commitInfo['prevHash'])
            <flux:tooltip content="Previous commit ([)">
                <flux:button aria-label="Previous commit" variant="ghost" size="xs" icon="chevron-left" icon:variant="outline"
                    href="/p/{{ $projectSlug }}/c/{{ $commitInfo['prevHash'] }}" wire:navigate />
            </flux:tooltip>
        @endif
        @if($commitInfo['nextHash'])
            <flux:tooltip content="Next commit (])">
                <flux:button aria-label="Next commit" variant="ghost" size="xs" icon="chevron-right" icon:variant="outline"
                    href="/p/{{ $projectSlug }}/c/{{ $commitInfo['nextHash'] }}" wire:navigate />
            </flux:tooltip>
        @endif
        <flux:tooltip content="Back to working directory">
            <flux:button aria-label="Back to working directory" variant="ghost" size="xs" icon="x-mark" icon:variant="outline"
                href="/p/{{ $projectSlug }}" wire:navigate />
        </flux:tooltip>
    </div>
</div>
