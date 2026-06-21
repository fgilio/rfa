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

{{-- This component lives in the persistent layout chrome, so its x-init runs
     once and is NOT re-run when Livewire morphs the body on navigation. The
     keymap store clears every binding on `livewire:navigating`, so these global
     shortcuts must be re-registered on `livewire:navigated` or they go dead
     after the first SPA navigation. Page/Livewire-scoped registrants re-init
     naturally; these layout-level ones don't. --}}
<div
    x-data="{
        registerGlobalShortcuts() {
            $store.shortcuts.register('help.shortcuts', () => $flux.modal('keyboard-shortcuts').show());
            {{-- ⌘↵ save is registered globally here, not on <x-comment-form>: that
                 component renders once per diff line, so registering there is
                 O(lines) of render + binding churn (measurably regresses diff-large).
                 One global handler routes to whichever comment form holds focus. --}}
            $store.shortcuts.register('comment.save', (e) => {
                const form = e.target.closest('[data-comment-form]');
                if (form) form.querySelector('[data-comment-save]')?.click();
            });
        },
    }"
    x-init="registerGlobalShortcuts()"
    {{-- x-on: form, not @livewire — the @ collides with Blade's @livewire directive. --}}
    x-on:livewire:navigated.window="registerGlobalShortcuts()"
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
