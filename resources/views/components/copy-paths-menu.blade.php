@props([
    'testidPrefix',
])

{{-- Renders a dropdown that copies the current visibly-filtered file list as
     names, relative paths, or full paths. Inherits Alpine state from the
     parent ⚡review-page scope: `visibleFileCount`, `copyVisibleFilePaths`. --}}
<div data-testid="{{ $testidPrefix }}" x-show="visibleFileCount > 0" x-cloak>
    <flux:dropdown position="bottom" align="end">
        <flux:tooltip>
            <flux:button variant="ghost" size="xs" icon="square-2-stack" icon:variant="outline"
                aria-label="Copy file paths"
                data-testid="{{ $testidPrefix }}-trigger" />
            <flux:tooltip.content>
                <span x-text="`Copy paths for ${visibleFileCount} ${visibleFileCount === 1 ? 'file' : 'files'}`"></span>
            </flux:tooltip.content>
        </flux:tooltip>
        <flux:menu>
            <flux:menu.item icon="document" icon:variant="outline" @click="copyVisibleFilePaths('name')">
                Copy file names
            </flux:menu.item>
            <flux:menu.item icon="document-duplicate" icon:variant="outline" @click="copyVisibleFilePaths('relative')">
                Copy relative paths
            </flux:menu.item>
            <flux:menu.item icon="link" icon:variant="outline" @click="copyVisibleFilePaths('full')">
                Copy full paths
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>
