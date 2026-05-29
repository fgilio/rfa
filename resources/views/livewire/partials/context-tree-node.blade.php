@php
    /**
     * @var array{name: string, path: string, children: array, files: array, hasContext: bool} $node
     * @var int $depth
     * @var string $filterMode
     * @var array<string, array<string, int>> $commentSummary
     */
    $padLeft = $depth * 12;
    $isRoot = $depth === 0;

    $matchesFilter = $isRoot || match ($filterMode) {
        'with-context' => $node['hasContext'],
        'missing' => ! $node['hasContext'],
        default => true,
    };
@endphp

@if($matchesFilter)
    @if(! $isRoot)
        <div
            class="flex items-center gap-1.5 py-0.5 select-none"
            style="padding-left: {{ $padLeft }}px"
            title="{{ $node['path'] }}"
        >
            <flux:icon icon="folder" variant="outline" class="!size-3 shrink-0 {{ $node['hasContext'] ? 'text-gh-green' : 'text-gh-muted/50' }}" />
            <span class="font-mono truncate {{ $node['hasContext'] ? 'text-gh-text' : 'text-gh-muted/70' }}">{{ $node['name'] }}</span>
        </div>
    @endif

    @foreach($node['files'] as $file)
        @php
            $summary = $commentSummary[$file['id']] ?? ['count' => 0, 'drafts' => 0];
            $kind = \App\Enums\AgentContextFileKind::from($file['kind']);
        @endphp
        <button
            type="button"
            @click="scrollToContextFile('{{ $file['id'] }}')"
            class="w-full text-left flex items-center gap-2 py-1 rounded hover:bg-gh-border/30 transition-colors"
            style="padding-left: {{ $padLeft + 18 }}px; padding-right: 6px;"
            data-testid="context-tree-file-{{ $file['id'] }}"
        >
            <span class="font-mono font-medium text-[10px] shrink-0 {{ $kind->badgeColorClass() }}">{{ $kind->badgeLabel() }}</span>
            <span class="font-mono truncate text-gh-text flex-1 min-w-0">{{ $file['basename'] }}</span>
            @if(! $file['isTracked'])
                <span class="font-mono text-[10px] text-gh-muted/70 shrink-0">untracked</span>
            @endif
            @if($file['isSymlink'])
                <flux:tooltip content="Symlink → {{ $file['symlinkTarget'] }}">
                    <flux:icon icon="link" variant="outline" class="!size-3 text-gh-muted shrink-0" />
                </flux:tooltip>
            @endif
            @if($summary['count'] > 0)
                <span class="font-mono text-[10px] text-gh-muted shrink-0">{{ $summary['count'] }}</span>
            @endif
            @if(($summary['drafts'] ?? 0) > 0)
                <span class="font-mono text-[10px] text-gh-draft shrink-0">{{ $summary['drafts'] }}d</span>
            @endif
        </button>
    @endforeach

    @foreach($node['children'] as $child)
        @include('livewire.partials.context-tree-node', [
            'node' => $child,
            'depth' => $depth + 1,
            'filterMode' => $filterMode,
            'commentSummary' => $commentSummary,
        ])
    @endforeach
@endif
