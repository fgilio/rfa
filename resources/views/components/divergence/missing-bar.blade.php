{{--
    Branch-divergence "missing target" bar.

    The one genuinely blocking divergence state: the branch being reviewed no
    longer exists (deleted / renamed / mid-rebase). Unlike Diverged/Detached
    (quiet header marker), this stays a full-width bar — but on-brand (house
    pattern from update-banner, gh-* tokens) rather than a stock-Flux callout,
    and now dismissible. Buttons call ReviewPage actions directly.
--}}
@props(['state', 'context' => []])

@if($state === \App\Enums\DivergenceState::MissingTarget)
    @php
        $target = $context['target'] ?? '';
        $currentBranch = $context['currentBranch'] ?? null;
    @endphp

    <div class="bg-gh-surface border-b border-gh-border px-5 py-2.5" role="alert" aria-live="assertive" data-testid="divergence-banner-missing">
        <div class="flex items-center gap-3">
            <flux:icon icon="exclamation-triangle" variant="outline" class="!size-4 shrink-0 text-gh-red" />
            <div class="min-w-0 flex-1">
                <p class="font-display font-semibold tracking-brutal text-sm">Review target <span class="font-mono">{{ $target }}</span> no longer exists</p>
                <p class="text-xs text-gh-muted">
                    The branch you were reviewing is gone — deleted, renamed, or mid-rebase.
                    @if($currentBranch)
                        Repo is now on <span class="font-mono text-gh-text">{{ $currentBranch }}</span>.
                    @endif
                </p>
            </div>
            <div class="flex items-center gap-1 shrink-0">
                <button type="button" wire:click="dismissMissingTarget" class="text-xs font-medium text-gh-muted hover:text-gh-text px-2.5 py-1.5 rounded-md transition-colors">Dismiss</button>
                @if($currentBranch)
                    <button type="button" wire:click="switchReviewToHead" class="text-xs font-medium font-display rounded-md px-3 py-1.5 bg-gh-accent text-gh-bg hover:opacity-90 transition-opacity">Switch review here</button>
                @endif
            </div>
        </div>
    </div>
@endif
