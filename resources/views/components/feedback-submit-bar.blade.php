@props([
    'submitted' => false,
    'exportResult' => null,
    'submittedHeading' => 'Review submitted',
    'submitLabel' => 'Submit review',
    'submitAction' => 'submitReview',
    'secondaryLabel' => null,
    'secondaryAction' => null,
    'secondaryIcon' => 'arrow-down-tray',
    'newRoundLabel' => 'Start a new review',
    'newRoundAction' => 'startNewReview',
    'placeholder' => 'Overall review comment (optional)',
    'copyAgainTooltip' => null,
])

{{--
    Fixed bottom bar shared by review-page and context-page. Two states:

    - $submitted is true after the page hands the export off and shows a
      "submitted, here is the file" confirmation with a Copy again and a
      "start over" button.
    - Otherwise renders the global-comment textarea, comment / draft
      counts, a clear-all affordance, and the primary submit button.

    The page provides the wording differences (review vs feedback, etc.)
    and the Livewire method names; the rest is identical between
    pages.
--}}

<div class="fixed bottom-0 left-0 right-0 z-50 bg-gh-bg/80 backdrop-blur-sm border-t border-gh-border">
    @if($submitted)
        <div class="px-5 py-3.5 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <flux:icon icon="check-circle" variant="outline" class="text-gh-green shrink-0" />
                <span class="font-semibold tracking-brutal shrink-0">{{ $submittedHeading }}</span>
                <x-stat-chip class="truncate">{{ $exportResult }}</x-stat-chip>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if ($copyAgainTooltip)
                    <flux:tooltip content="{{ $copyAgainTooltip }}">
                        <flux:button
                            size="sm"
                            icon="clipboard-document"
                            icon:variant="outline"
                            @click="$dispatch('copy-to-clipboard', { text: @js($exportResult), toast: 'Copied again' })"
                        >
                            Copy again
                        </flux:button>
                    </flux:tooltip>
                @else
                    <flux:button
                        size="sm"
                        icon="clipboard-document"
                        icon:variant="outline"
                        @click="$dispatch('copy-to-clipboard', { text: @js($exportResult), toast: 'Copied again' })"
                    >
                        Copy again
                    </flux:button>
                @endif
                <flux:button
                    variant="primary"
                    size="sm"
                    icon="pencil-square"
                    icon:variant="outline"
                    wire:click="{{ $newRoundAction }}"
                >
                    {{ $newRoundLabel }}
                </flux:button>
            </div>
        </div>
    @else
        <div class="px-5 py-3.5 flex items-center gap-4"
            x-data="{
                armed: false,
                get commentCount() { return $wire.comments.filter(c => !c.isDraft).length },
                get draftCount() { return $wire.comments.filter(c => c.isDraft).length },
                get hasGlobal() { return ($wire.globalComment || '').trim().length > 0 }
            }"
        >
            <div class="flex-1">
                <flux:textarea
                    wire:model.live.debounce.500ms="globalComment"
                    placeholder="{{ $placeholder }}"
                    rows="auto"
                    resize="none"
                    class="font-mono text-xs"
                />
            </div>
            <div class="flex items-center gap-3 shrink-0">
                <template x-if="commentCount > 0">
                    <span class="font-mono text-xs text-gh-muted" x-text="commentCount + ' ' + (commentCount === 1 ? 'comment' : 'comments')"></span>
                </template>
                <template x-if="draftCount > 0">
                    <span class="font-mono text-xs text-gh-draft" x-text="draftCount + ' ' + (draftCount === 1 ? 'draft' : 'drafts')"></span>
                </template>
                <template x-if="commentCount + draftCount > 0">
                    <div class="flex items-center gap-3">
                        <x-arm-commit-button
                            icon="trash"
                            tooltip="Clear all comments"
                            @confirmed="$wire.clearAllComments()"
                        />
                        <span class="w-px h-4 bg-gh-border"></span>
                    </div>
                </template>
                {{-- Drafts won't be included: arm-to-confirm inline (no native confirm() dialog). --}}
                @if($secondaryLabel !== null && $secondaryAction !== null)
                    <flux:button
                        variant="ghost"
                        icon="{{ $secondaryIcon }}"
                        icon:variant="outline"
                        wire:click="{{ $secondaryAction }}"
                        wire:loading.attr="disabled"
                        wire:target="{{ $secondaryAction }}"
                    >
                        {{ $secondaryLabel }}
                    </flux:button>
                @endif

                <flux:button
                    variant="primary"
                    @click="if (draftCount > 0 && !armed) { armed = true; return; } $wire.{{ $submitAction }}()"
                    @click.outside="armed = false"
                    {{-- Drop a stale arm if the drafts it was armed against vanish (e.g.
                         deleted via a skipRender'd action that leaves this x-data intact),
                         so a later submit can't skip the confirm against a different set. --}}
                    x-effect="if (draftCount === 0) { armed = false }"
                    wire:loading.attr="disabled"
                    wire:target="{{ $submitAction }}"
                    x-bind:disabled="commentCount === 0 && !hasGlobal"
                >
                    <span x-show="!(armed && draftCount > 0)">{{ $submitLabel }}</span>
                    <span x-show="armed && draftCount > 0" x-cloak x-text="`Submit without ${draftCount} draft${draftCount === 1 ? '' : 's'}?`"></span>
                </flux:button>
            </div>
        </div>
    @endif
</div>
