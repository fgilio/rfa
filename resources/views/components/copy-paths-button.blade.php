@props([
    'mode' => 'bulk',
    'path' => null,
    'repoPath' => '',
    'testidPrefix' => null,
    'size' => 'xs',
    'visibleCount' => null,
])

{{-- Unified copy-paths affordance.

     Left-click              → copy relative path(s) immediately + toast
     Right-click / Shift+F10 → open menu with name / relative / full options

     Modes:
       - 'single' — copies one path client-side; requires :path. Pass :repo-path
                    so "Copy full path" works on pages without the ⚡review-page
                    Alpine root.
       - 'bulk'   — copies the currently server-visible (filtered) files. The copy
                    is server-owned (ReviewPage::copyVisiblePaths), so it always
                    matches the active filter. Pass :visible-count so the trigger
                    hides when nothing is visible; the menu count is read live from
                    the review root.

     Left-click on the trigger should copy directly, not toggle the dropdown
     (Flux's default for any trigger inside `<flux:dropdown>`). The wrapper
     intercepts the click via `@click.capture.stop` before Flux sees it, and
     wraps only the trigger — menu items are siblings, so their clicks aren't
     captured and reach Flux normally.
--}}
<div data-testid="{{ $testidPrefix }}"
    @if ($mode === 'bulk' && ($visibleCount ?? 0) <= 0) hidden @endif
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
            <flux:menu.item icon="document" icon:variant="outline" @click="copy('name')">
                <span x-text="nameLabel">Copy file name</span>
            </flux:menu.item>
            <flux:menu.item icon="document-duplicate" icon:variant="outline" @click="copy('relative')">
                <span x-text="relativeLabel">Copy relative path</span>
            </flux:menu.item>
            <flux:menu.item icon="link" icon:variant="outline" @click="copy('full')">
                <span x-text="fullLabel">Copy full path</span>
            </flux:menu.item>
        </flux:menu>
    </flux:dropdown>
</div>

@once
    <script src="/js/copy-paths-button.js"></script>
@endonce
