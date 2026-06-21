@php
    /**
     * Cheat sheet for every documented keyboard shortcut. Rendered once from the
     * layout. Content is driven entirely by config/shortcuts.php — the same
     * catalog the handlers register against — so it can never list a combo that
     * doesn't fire, or miss one that does.
     */
    $catalog = collect(\App\Support\Shortcuts::all());
    $groupIndex = array_flip(\App\Support\Shortcuts::groups());
    $grouped = $catalog
        ->groupBy('group')
        ->sortBy(fn ($_, $group) => $groupIndex[$group] ?? PHP_INT_MAX);
@endphp

<div
    x-data
    x-init="$store.shortcuts.register('help.shortcuts', () => $flux.modal('keyboard-shortcuts').show())"
>
    <flux:modal name="keyboard-shortcuts" class="md:w-[32rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Keyboard shortcuts</flux:heading>
                <flux:subheading>Press <kbd class="font-mono">?</kbd> any time to open this list.</flux:subheading>
            </div>

            @foreach($grouped as $group => $shortcuts)
                <div class="space-y-1.5">
                    <p class="section-label text-gh-muted">{{ $group }}</p>
                    <div class="divide-y divide-gh-border/60">
                        @foreach($shortcuts as $shortcut)
                            <div class="flex items-center justify-between gap-4 py-1.5">
                                <span class="text-sm text-gh-text">{{ $shortcut['label'] }}</span>
                                <kbd class="shrink-0 font-mono text-xs px-2 py-0.5 rounded border border-gh-border bg-gh-surface text-gh-muted">{{ $shortcut['display'] ?? $shortcut['combo'] }}</kbd>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </flux:modal>
</div>
