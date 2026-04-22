{{--
    Mode contract (hidden coupling; document before changing):
    - 'picker': rows render inside the picker's Alpine scope. Requires the
      parent x-data to define `selectSlug(slug)` and a `selectedIndex`
      int, and to treat `[data-project-picker-row]` as the keyboard-nav
      target list.
    - 'page': rows dispatch to the parent Livewire component. Requires the
      parent to expose `selectProject(string $slug)` and
      `removeProject(int $id)` methods.
--}}
@props([
    'mode' => 'page',
    'groups' => [],
    'currentSlug' => '',
    'search' => '',
    'matchCount' => 0,
    'keyPrefix' => 'project',
])

@php
    $rowIndex = 0;
    $isPicker = $mode === 'picker';
@endphp

@if($matchCount === 0)
    <div class="px-4 py-10 text-center">
        <p class="font-mono text-xs text-gh-muted">
            @if($search !== '')
                No matching repos
            @else
                No repos yet
            @endif
        </p>
    </div>
@endif

@foreach($groups as $commonDir => $projects)
    <div wire:key="{{ $keyPrefix }}-group-{{ md5($commonDir) }}">
        @if(count($projects) > 1)
            <div class="px-3 pt-3 pb-1">
                <span class="section-label text-gh-muted font-mono truncate block">{{ $commonDir }}</span>
            </div>
        @endif

        @foreach($projects as $project)
            @php
                $isCurrent = $project['slug'] === $currentSlug;
                $commentCount = (int) ($project['comment_count'] ?? 0);

                if ($isCurrent) {
                    $confirmMessage = "Remove '{$project['name']}' and all its review data? You'll return to the repo picker.";
                } else {
                    $confirmMessage = "Remove '{$project['name']}' from the list?";
                }

                if ($commentCount > 0) {
                    $confirmMessage .= " {$commentCount} " . Str::plural('comment', $commentCount) . ' will be deleted.';
                }
            @endphp
            <div
                wire:key="{{ $keyPrefix }}-project-{{ $project['id'] }}"
                @if($isPicker)
                    data-project-picker-row
                    data-slug="{{ $project['slug'] }}"
                @endif
                x-data="{ status: null, loaded: false }"
                x-intersect.once="setTimeout(() => {
                    fetch('{{ route('api.status', $project['id']) }}')
                        .then(r => r.json())
                        .then(d => { status = d; loaded = true; })
                        .catch(() => { loaded = true; });
                }, {{ $rowIndex * 40 }})"
                @class([
                    'group px-3 py-2.5 border-b border-gh-border/50 last:border-b-0 cursor-pointer transition-colors',
                    'bg-gh-link/5 border-l-2 border-l-gh-link' => $isCurrent,
                    'hover:bg-gh-border/30' => ! $isPicker,
                ])
                @if($isPicker)
                    :class="selectedIndex === {{ $rowIndex }} ? 'bg-gh-text/10' : 'hover:bg-gh-border/30'"
                    @click="selectSlug('{{ $project['slug'] }}')"
                    @mouseenter="selectedIndex = {{ $rowIndex }}"
                @else
                    wire:click="selectProject('{{ $project['slug'] }}')"
                @endif
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <span class="shrink-0 w-3.5 flex items-center justify-center">
                            @if($isCurrent)
                                <flux:icon icon="check" variant="outline" class="!size-3.5 text-gh-link" />
                            @else
                                <span class="h-1.5 w-1.5 rounded-full bg-gh-muted/30"></span>
                            @endif
                        </span>
                        <span @class([
                            'font-semibold tracking-brutal text-sm truncate',
                            'text-gh-link' => $isCurrent,
                        ])>{{ $project['name'] }}</span>
                        @if($project['is_worktree'])
                            <flux:badge size="sm" color="yellow">worktree</flux:badge>
                        @endif
                        @if($project['branch'])
                            <span class="text-[11px] font-mono text-gh-muted px-1.5 py-0.5 rounded border border-gh-border shrink-0 truncate max-w-[140px]">{{ $project['branch'] }}</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2 shrink-0 text-xs font-mono">
                        @if($commentCount > 0)
                            <span class="flex items-center gap-1 text-gh-link">
                                <flux:icon icon="chat-bubble-left" variant="outline" class="!size-3" />
                                {{ $commentCount }}
                            </span>
                        @endif
                        <span x-show="loaded && status?.dirty" x-cloak>
                            <span class="text-gh-green" x-text="'+' + (status?.additions || 0)"></span>
                            <span class="text-gh-red" x-text="'-' + (status?.deletions || 0)"></span>
                        </span>
                        <span class="text-gh-muted/70">{{ $project['last_active_ago'] }}</span>
                        <flux:button
                            variant="ghost"
                            size="xs"
                            icon="trash"
                            icon:variant="outline"
                            wire:click.stop="removeProject({{ $project['id'] }})"
                            wire:confirm="{{ $confirmMessage }}"
                            @class([
                                'text-gh-muted hover:text-red-500 transition-opacity',
                                'opacity-0 group-hover:opacity-100 focus-visible:opacity-100' => $isPicker,
                                'opacity-60 hover:opacity-100' => ! $isPicker,
                            ])
                            tooltip="Remove repo"
                            aria-label="Remove {{ $project['name'] }}"
                            data-testid="remove-repo-{{ $project['slug'] }}"
                        />
                    </div>
                </div>
                <p class="mt-1 font-mono text-[11px] text-gh-muted/70 truncate pl-6">{{ $project['path'] }}</p>
            </div>
            @php $rowIndex++; @endphp
        @endforeach
    </div>
@endforeach
