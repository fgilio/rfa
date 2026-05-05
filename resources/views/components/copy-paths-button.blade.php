@props([
    'mode' => 'bulk',
    'path' => null,
    'testidPrefix' => null,
    'size' => 'xs',
])

{{-- Unified copy-paths affordance.

     Left-click             → copy relative path(s) immediately + toast
     Right-click / 400ms hold → open menu with name / relative / full options

     Modes:
       - 'single' — copies one path; requires :path. Always visible.
       - 'bulk'   — copies the currently filter-visible files. Inherits
                    `sourceFileEntries`, `fileMatchesFilter`, `pathBase`,
                    `buildFullPath` from the ⚡review-page Alpine root.
                    Hidden when nothing matches the filter.

     The gesture wrapper sits *inside* `<flux:dropdown>` and wraps only the
     trigger. Menu items are siblings of the wrapper, so menu-item clicks do
     not pass through `@click.capture` and reach Flux normally.
--}}
<div data-testid="{{ $testidPrefix }}"
    @if ($mode === 'bulk') x-show="visibleFileCount > 0" x-cloak @endif
    x-data="copyPathsButton({ mode: @js($mode), singlePath: @js($path) })"
    class="inline-flex">
    <flux:dropdown position="bottom" align="end" x-ref="dropdown">
        <div class="contents"
            @contextmenu.prevent.stop="openMenu()"
            @click.capture.stop="onClick($event)"
            @mousedown="onMouseDown($event)"
            @mouseup="cancelLongPress()"
            @mouseleave="cancelLongPress()">
            <flux:tooltip>
                <flux:button variant="ghost" size="{{ $size }}" icon="square-2-stack" icon:variant="outline"
                    aria-label="{{ $mode === 'single' ? 'Copy file path' : 'Copy paths' }}"
                    data-testid="{{ $testidPrefix }}-trigger" />
                <flux:tooltip.content>
                    <span class="block" x-text="primaryLabel"></span>
                    <span class="block opacity-60">Right-click for options</span>
                </flux:tooltip.content>
            </flux:tooltip>
        </div>
        <flux:menu>
            <flux:menu.item icon="document" icon:variant="outline" @click="copyAs('name')">
                Copy file names
            </flux:menu.item>
            <flux:menu.item icon="document-duplicate" icon:variant="outline" @click="copyAs('relative')">
                Copy relative paths
            </flux:menu.item>
            <flux:menu.item icon="link" icon:variant="outline" @click="copyAs('full')">
                Copy full paths
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>

@once
    <script src="/js/copy-paths-button.js"></script>
@endonce
