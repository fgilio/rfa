<div
    x-data="{
        activeFile: null,
        viewedFiles: {{ Js::from((object) collect($files)->filter(fn($f) => in_array($f['path'], $viewedFiles))->pluck('id')->flip()->map(fn() => true)->all()) }},
        scrollToFile(id) {
            this.activeFile = id;
            this.$dispatch('expand-file', { id });
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }"
    @file-viewed-changed.window="viewedFiles[$event.detail.id] = $event.detail.viewed"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).catch(() => {});
    "
    @keydown.window="
        if ($event.target.tagName === 'TEXTAREA' || $event.target.tagName === 'INPUT') return;
        if ($event.shiftKey && $event.key === 'C') { $dispatch('collapse-all-files'); $event.preventDefault(); }
        if ($event.shiftKey && $event.key === 'E') { $dispatch('expand-all-files'); $event.preventDefault(); }
    "
>
    {{-- Header --}}
    <header class="sticky top-0 z-50 bg-gh-surface border-b border-gh-border px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="lg">rfa</flux:heading>
            <flux:text variant="subtle" size="sm">{{ basename($repoPath) }}</flux:text>
        </div>
        <div class="flex items-center gap-3 text-xs">
            <flux:text variant="subtle" size="sm" inline>{{ count($files) }} {{ Str::plural('file', count($files)) }}</flux:text>
            <flux:text variant="subtle" size="sm" inline
                x-show="Object.values(viewedFiles).filter(Boolean).length > 0"
                x-text="Object.values(viewedFiles).filter(Boolean).length + '/{{ count($files) }} viewed'"
                x-cloak />
            <flux:badge color="green" size="sm">+{{ collect($files)->sum('additions') }}</flux:badge>
            <flux:badge color="red" size="sm">-{{ collect($files)->sum('deletions') }}</flux:badge>
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:tooltip content="Collapse all (Shift+C)">
                <flux:button variant="ghost" size="sm" icon="bars-arrow-up"
                    @click="$dispatch('collapse-all-files')" />
            </flux:tooltip>
            <flux:tooltip content="Expand all (Shift+E)">
                <flux:button variant="ghost" size="sm" icon="bars-arrow-down"
                    @click="$dispatch('expand-all-files')" />
            </flux:tooltip>
            <span class="w-px h-4 bg-gh-border"></span>
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="moon" x-show="! $flux.dark" x-cloak />
            <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" variant="ghost" size="sm"
                icon="sun" x-show="$flux.dark" />
        </div>
    </header>

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="w-72 shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-surface/50 hidden lg:block">
            <div class="p-3">
                <flux:heading class="!text-xs uppercase tracking-wide mb-2">Files</flux:heading>
                @foreach($files as $file)
                    @php
                        [$badgeColor, $badgeLabel] = match($file['status']) {
                            'added' => ['green', 'A'],
                            'deleted' => ['red', 'D'],
                            'renamed' => ['yellow', 'R'],
                            default => ['yellow', 'M'],
                        };
                    @endphp
                    <button
                        @click="scrollToFile('{{ $file['id'] }}')"
                        class="w-full text-left px-2 py-1.5 rounded text-xs hover:bg-gh-border/50 flex items-center gap-2 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-border/50 text-gh-accent' : 'text-gh-text'"
                    >
                        <flux:badge variant="solid" :color="$badgeColor" size="sm" class="!text-[10px] !px-1 !py-0 w-4 shrink-0">{{ $badgeLabel }}</flux:badge>
                        <span class="truncate">{{ $file['path'] }}</span>
                        <flux:icon icon="check" variant="micro" x-show="viewedFiles['{{ $file['id'] }}']"
                            class="text-gh-green shrink-0" x-cloak />
                        <span class="ml-auto flex gap-1 shrink-0">
                            @if($file['additions'] > 0)
                                <flux:badge color="green" size="sm">+{{ $file['additions'] }}</flux:badge>
                            @endif
                            @if($file['deletions'] > 0)
                                <flux:badge color="red" size="sm">-{{ $file['deletions'] }}</flux:badge>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </aside>

        {{-- Main content --}}
        <main class="flex-1 min-w-0 pb-24">
            @if(empty($files))
                <div class="flex items-center justify-center h-[60vh]">
                    <div class="text-center">
                        <flux:icon icon="document-magnifying-glass" variant="outline" class="mx-auto mb-3 text-gh-muted" />
                        <flux:heading class="mb-2">No changes detected</flux:heading>
                        <flux:text variant="subtle" size="sm">Make some changes and run rfa again</flux:text>
                    </div>
                </div>
            @else
                @foreach($files as $file)
                    <div id="{{ $file['id'] }}" class="border-b border-gh-border">
                        <livewire:diff-file
                            :key="$file['id']"
                            :file="$file"
                            :file-comments="$this->groupedComments[$file['id']] ?? []"
                            :is-viewed="in_array($file['path'], $viewedFiles)"
                            :repo-path="$repoPath"
                        />
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>
