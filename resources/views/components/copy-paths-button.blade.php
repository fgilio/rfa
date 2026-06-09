@props([
    'mode' => 'bulk',
    'path' => null,
    'repoPath' => '',
    'testidPrefix' => null,
    'size' => 'xs',
    'entries' => null,
    'visibleCount' => null,
])

{{-- Unified copy-paths affordance.

     Left-click              → copy relative path(s) immediately + toast
     Right-click / Shift+F10 → open menu with name / relative / full options

     Modes:
       - 'single' — copies one path; requires :path. Pass :repo-path so
                    "Copy full paths" works on pages without the ⚡review-page
                    Alpine root.
       - 'bulk'   — copies the currently server-visible files. Prefer passing
                    :entries and :visible-count; falls back to the review-page
                    Alpine root for older call sites.

     Left-click on the trigger should copy directly, not toggle the dropdown
     (Flux's default for any trigger inside `<flux:dropdown>`). The wrapper
     intercepts the click via `@click.capture.stop` before Flux sees it, and
     wraps only the trigger — menu items are siblings, so their clicks aren't
     captured and reach Flux normally.
--}}
@php
    $serverVisibleCount = $mode === 'bulk' && $visibleCount !== null ? (int) $visibleCount : null;
@endphp

<div data-testid="{{ $testidPrefix }}"
    @if ($mode === 'bulk')
        data-source-file-entries='@json($entries)'
        data-visible-file-count="{{ $visibleCount }}"
        @if($serverVisibleCount === null) x-show="bulkVisibleCount > 0" x-cloak @endif
        @if($serverVisibleCount !== null && $serverVisibleCount <= 0) hidden @endif
    @endif
    x-data="copyPathsButton({ mode: @js($mode), singlePath: @js($path), repoPath: @js($repoPath) })"
    class="inline-flex">
    <flux:dropdown position="bottom" align="end" x-ref="dropdown">
        <div class="contents"
            @contextmenu.prevent.stop="openMenu()"
            @keydown.shift.f10.prevent.stop="openMenu()"
            @keydown.context-menu.prevent.stop="openMenu()"
            @click.capture.stop="onClick($event)">
            <flux:tooltip>
                <flux:button variant="ghost" size="{{ $size }}" icon="square-2-stack" icon:variant="outline"
                    aria-label="{{ $mode === 'single' ? 'Copy file path' : 'Copy paths' }}"
                    data-testid="{{ $testidPrefix }}-trigger" />
                <flux:tooltip.content>
                    <span class="block" x-text="primaryLabel"></span>
                    <span class="block opacity-60">Right-click or Shift+F10 for options</span>
                </flux:tooltip.content>
            </flux:tooltip>
        </div>
        <flux:menu>
            <flux:menu.item icon="document" icon:variant="outline" @click="copyAs('name')">
                <span x-text="nameLabel">Copy file name</span>
            </flux:menu.item>
            <flux:menu.item icon="document-duplicate" icon:variant="outline" @click="copyAs('relative')">
                <span x-text="relativeLabel">Copy relative path</span>
            </flux:menu.item>
            <flux:menu.item icon="link" icon:variant="outline" @click="copyAs('full')">
                <span x-text="fullLabel">Copy full path</span>
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>

@once
    <script src="/js/copy-paths-button.js"></script>
@endonce
