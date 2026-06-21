@php
    /**
     * Cheat sheet for every documented keyboard shortcut, rendered once from
     * the layout. The content comes entirely from config/shortcuts.php (the
     * same catalog the handlers register against), so it can never list a
     * combo that doesn't fire or miss one that does.
     */
    $catalog = collect(\App\Support\Shortcuts::all());
    $groupIndex = array_flip(\App\Support\Shortcuts::groups());
    $grouped = $catalog
        ->groupBy('group')
        ->sortBy(fn ($_, $group) => $groupIndex[$group] ?? PHP_INT_MAX);
@endphp

{{-- This component lives in the persistent layout chrome, so its x-init runs
     once and does not re-run when Livewire morphs the body on navigation.
     The keymap store clears every binding on `livewire:navigating`, so the
     global shortcuts re-register on `livewire:navigated`. Page and Livewire
     scoped registrants re-init on navigation on their own. --}}
<div
    x-data="{
        registerGlobalShortcuts() {
            $store.shortcuts.register('help.shortcuts', () => $flux.modal('keyboard-shortcuts').show());
            {{-- One global ⌘↵ handler routes to the comment form that holds focus.
                 The form renders once per diff line, so binding the handler on
                 each form would cost O(lines) of registration churn on large diffs. --}}
            $store.shortcuts.register('comment.save', (e) => {
                const form = e.target.closest('[data-comment-form]');
                if (form) form.querySelector('[data-comment-save]')?.click();
            });
        },
    }"
    x-init="registerGlobalShortcuts()"
    {{-- The @ in `@livewire:navigated.window` collides with Blade's @livewire
         directive, so this uses the x-on: form. --}}
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
