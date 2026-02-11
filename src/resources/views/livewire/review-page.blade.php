<div
    x-data="{
        activeFile: null,
        scrollToFile(id) {
            this.activeFile = id;
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }"
    @copy-to-clipboard.window="
        navigator.clipboard.writeText($event.detail.text).catch(() => {});
    "
>
    {{-- Header --}}
    <header class="sticky top-0 z-50 bg-gh-surface border-b border-gh-border px-4 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-gh-text font-semibold text-base">rfa</h1>
            <span class="text-gh-muted text-xs">{{ basename($repoPath) }}</span>
        </div>
        <div class="flex items-center gap-3 text-xs text-gh-muted">
            <span>{{ count($files) }} {{ Str::plural('file', count($files)) }}</span>
            <span class="text-green-400">+{{ collect($files)->sum('additions') }}</span>
            <span class="text-red-400">-{{ collect($files)->sum('deletions') }}</span>
        </div>
    </header>

    <div class="flex">
        {{-- Sidebar --}}
        <aside class="w-72 shrink-0 sticky top-[53px] h-[calc(100vh-53px)] overflow-y-auto border-r border-gh-border bg-gh-surface/50 hidden lg:block">
            <div class="p-3">
                <h2 class="text-xs font-semibold text-gh-muted uppercase tracking-wide mb-2">Files</h2>
                @foreach($files as $file)
                    <button
                        @click="scrollToFile('{{ $file['id'] }}')"
                        class="w-full text-left px-2 py-1.5 rounded text-xs hover:bg-gh-border/50 flex items-center gap-2 group transition-colors"
                        :class="activeFile === '{{ $file['id'] }}' ? 'bg-gh-border/50 text-gh-accent' : 'text-gh-text'"
                    >
                        @if($file['status'] === 'added')
                            <span class="text-green-400 text-[10px] font-bold w-4 shrink-0">A</span>
                        @elseif($file['status'] === 'deleted')
                            <span class="text-red-400 text-[10px] font-bold w-4 shrink-0">D</span>
                        @elseif($file['status'] === 'renamed')
                            <span class="text-yellow-400 text-[10px] font-bold w-4 shrink-0">R</span>
                        @else
                            <span class="text-yellow-400 text-[10px] font-bold w-4 shrink-0">M</span>
                        @endif
                        <span class="truncate">{{ $file['path'] }}</span>
                        <span class="ml-auto flex gap-1 shrink-0">
                            @if($file['additions'] > 0)
                                <span class="text-green-400">+{{ $file['additions'] }}</span>
                            @endif
                            @if($file['deletions'] > 0)
                                <span class="text-red-400">-{{ $file['deletions'] }}</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </aside>

        {{-- Main content --}}
        <main class="flex-1 min-w-0 pb-24">
            @if(empty($files))
                <div class="flex items-center justify-center h-[60vh] text-gh-muted">
                    <div class="text-center">
                        <p class="text-lg mb-2">No changes detected</p>
                        <p class="text-xs">Make some changes and run rfa again</p>
                    </div>
                </div>
            @else
                @foreach($files as $fileIndex => $file)
                    <div id="{{ $file['id'] }}" class="border-b border-gh-border">
                        @include('livewire.diff-file', ['file' => $file, 'fileIndex' => $fileIndex])
                    </div>
                @endforeach
            @endif
        </main>
    </div>

    {{-- Submit bar --}}
    @include('livewire.submit-bar')
</div>
