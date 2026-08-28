<?php

use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Hierarchical sidebar for the Context page.
 *
 * Renders a folder tree of the project; each leaf is an agent-context file
 * found by DiscoverAgentContextFilesAction. Folders are tinted by coverage:
 * green when they contain a context file (directly or transitively), gray
 * when they're a candidate dir with no context file, hidden when they have
 * no source files at all (vendor/, dist/ — already filtered upstream).
 *
 * Built bespoke for this page on purpose — review-page's flat-grouped
 * sidebar is not a tree and shouldn't be coerced into one.
 */
new class extends Component
{
    /** @var array<int, array<string, mixed>> */
    #[Locked]
    public array $contextFiles = [];

    /** all | with-context | missing */
    public string $filterMode = 'all';

    /** @var array<string, array<string, int>> Comment counts per fileId, by status. */
    #[Locked]
    public array $commentSummary = [];

    /**
     * Update the per-file comment counts in place. Page dispatches this when
     * a context-page mutation is wrapped in skipRender(): the parent stays
     * unrendered (so N diff-file children don't rehydrate), but the sidebar
     * still needs to reflect the new counts/draft state.
     *
     * @param  array<string, array{count: int, drafts: int}>  $summary
     */
    #[On('context-comment-summary-updated')]
    public function syncCommentSummary(array $summary): void
    {
        $this->commentSummary = $summary;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function tree(): array
    {
        $root = ['name' => '', 'path' => '', 'children' => [], 'files' => [], 'hasContext' => false];

        foreach ($this->contextFiles as $file) {
            $segments = $file['path'] === '' ? [] : explode('/', $file['path']);
            $basename = array_pop($segments);

            $node = &$root;
            foreach ($segments as $segment) {
                if (! isset($node['children'][$segment])) {
                    $node['children'][$segment] = [
                        'name' => $segment,
                        'path' => $node['path'] === '' ? $segment : $node['path'].'/'.$segment,
                        'children' => [],
                        'files' => [],
                        'hasContext' => false,
                    ];
                }
                $node = &$node['children'][$segment];
            }

            $node['files'][] = ['basename' => $basename] + $file;
            $node['hasContext'] = true;
            unset($node);

            // Walk back up and tag every ancestor as covered.
            $cursor = &$root;
            $cursor['hasContext'] = true;
            foreach ($segments as $segment) {
                $cursor = &$cursor['children'][$segment];
                $cursor['hasContext'] = true;
            }
            unset($cursor);
        }

        $sortChildren = function (array &$node) use (&$sortChildren): void {
            ksort($node['children']);
            usort($node['files'], fn (array $a, array $b) => strcmp($a['basename'], $b['basename']));
            foreach ($node['children'] as &$child) {
                $sortChildren($child);
            }
        };
        $sortChildren($root);

        // Collapse single-child, file-less folder chains into one breadcrumb row
        // (app › Console › Commands › Pla › Db  →  app/Console/Commands/Pla/Db),
        // like VS Code / GitHub, so indentation tracks real branching, not depth.
        // The root stays the container; only its descendant chains are merged.
        $compact = function (array $node) use (&$compact): array {
            foreach ($node['children'] as $key => $child) {
                $child = $compact($child);

                while (count($child['children']) === 1 && empty($child['files'])) {
                    $grandchild = reset($child['children']);
                    $child['name'] = $child['name'].'/'.$grandchild['name'];
                    $child['path'] = $grandchild['path'];
                    $child['files'] = $grandchild['files'];
                    $child['hasContext'] = $grandchild['hasContext'];
                    $child['children'] = $grandchild['children'];
                }

                $node['children'][$key] = $child;
            }

            return $node;
        };

        return $compact($root);
    }
};

?>

<div data-testid="context-tree">
    <div
        data-testid="sidebar-filter-bar"
        class="sticky top-0 z-20 -mx-4 -mt-4 space-y-3 bg-gh-bg px-4 pt-4 pb-3"
    >
        <div class="flex items-center justify-between">
            <span class="section-label text-gh-muted">Context files</span>
            <span class="font-mono text-[10px] text-gh-muted">{{ count($contextFiles) }}</span>
        </div>

        <flux:select wire:model.live="filterMode" size="sm">
            <flux:select.option value="all">All paths</flux:select.option>
            <flux:select.option value="with-context">With context only</flux:select.option>
        </flux:select>
    </div>

    @if(empty($contextFiles))
        <div class="text-xs text-gh-muted px-1 py-4 text-center">
            No agent context files in this repo.
        </div>
    @else
        <div class="text-xs">
            @include('livewire.partials.context-tree-node', [
                'node' => $this->tree,
                'depth' => 0,
                'filterMode' => $filterMode,
                'commentSummary' => $commentSummary,
            ])
        </div>
    @endif
</div>
