{{-- Width and collapsed state persist across pages via Alpine.store('settings')
     (sidebarWidth / sidebarCollapsed).

     This shell owns the sidebar, so it also owns the visibility shortcut
     (hyper+S) and the bridge for the native "Toggle Sidebar" View-menu item,
     which crosses main→renderer as a window event the layout re-dispatches.
     Both, and the header button, mutate the store through toggleSidebar(). --}}

<div
    {{ $attributes->merge(['class' => 'flex']) }}
    :style="{ '--sidebar-w': $store.settings.sidebarWidth + 'px' }"
    @rfa-toggle-sidebar.window="$store.settings.toggleSidebar()"
    x-data="{
        resizing: false,
        init() {
            $store.shortcuts.register('sidebar.toggle', () => $store.settings.toggleSidebar());
        },
        startResize($event) {
            this.resizing = true;
            const startX = $event.clientX;
            const startWidth = $store.settings.sidebarWidth;
            const aside = this.$refs.sidebar;
            const main = aside.parentElement.querySelector('main');
            let raf = null;
            let currentWidth = startWidth;

            // Float sidebar above main so diff DOM never reflows during drag
            aside.style.position = 'fixed';
            aside.style.left = '0';
            aside.style.zIndex = '40';
            aside.style.willChange = 'width';
            main.style.marginLeft = startWidth + 'px';
            document.body.classList.add('cursor-col-resize', 'select-none');

            const onMove = (e) => {
                currentWidth = Math.min(600, Math.max(200, startWidth + e.clientX - startX));
                if (raf) return;
                raf = requestAnimationFrame(() => {
                    aside.style.width = currentWidth + 'px';
                    raf = null;
                });
            };
            const finish = () => {
                if (raf) { cancelAnimationFrame(raf); raf = null; }
                aside.style.position = '';
                aside.style.left = '';
                aside.style.zIndex = '';
                aside.style.willChange = '';
                aside.style.width = 'var(--sidebar-w, 288px)';
                main.style.marginLeft = '';
                this.resizing = false;
                document.body.classList.remove('cursor-col-resize', 'select-none');
                if (currentWidth !== startWidth) {
                    $store.settings.sidebarWidth = currentWidth;
                }
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', finish);
                window.removeEventListener('blur', finish);
            };

            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', finish);
            // Alt-tab or app switch mid-drag never fires mouseup, so without
            // this listener resizing stays true and main keeps pointer-events-none.
            window.addEventListener('blur', finish);
        }
    }"
>
    {{-- Visibility is the store's business alone. The old `hidden lg:block`
         gating fought the toggle: the window floor is 800px and `lg` starts at
         1024, so between the two the button swapped its icon and wrote state
         while the sidebar stayed hidden and unreachable. RFA is a single
         desktop window — breakpoints are not a concern here (see CLAUDE.md).

         x-show, not a class swap: it only overrides `display`, so the resize
         handler's inline width writes stay on an element that is merely
         hidden, never re-created. --}}
    <aside
        class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-bg"
        style="width: var(--sidebar-w, 288px); height: calc(100vh - var(--header-h) - var(--feedback-bar-h));"
        x-ref="sidebar"
        x-show="!$store.settings.sidebarCollapsed"
        {{-- Not x-cloak: that would hide the sidebar until Alpine boots on
             every load, and expanded is the default, so the common case would
             flash a full-width diff and then reflow. The boot class only fires
             for the state that actually needs suppressing. --}}
        data-sidebar-collapsible
        data-testid="sidebar"
    >
        {{ $sidebar }}
    </aside>

    <div
        data-testid="sidebar-resize-handle"
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize sidebar"
        title="Drag to resize · double-click to reset"
        class="group/resize flex sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] w-0 cursor-col-resize items-center justify-center z-10 shrink-0"
        style="height: calc(100vh - var(--header-h) - var(--feedback-bar-h)); padding: 0 6px; margin: 0 -6px;"
        {{-- Nothing to resize while the sidebar is hidden, and its ±6px
             hit area would otherwise sit over the first diff column. --}}
        x-show="!$store.settings.sidebarCollapsed"
        data-sidebar-collapsible
        @mousedown="startResize($event)"
        @dblclick="$store.settings.sidebarWidth = 288"
    >
        <div class="absolute inset-y-0 w-px bg-transparent group-hover/resize:bg-gh-muted/40 transition-colors"></div>
        <div class="absolute px-1 py-1.5 rounded-full bg-gh-surface border border-gh-border shadow-sm opacity-0 group-hover/resize:opacity-100 transition-opacity pointer-events-none flex flex-col items-center gap-[3px]">
            <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
            <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
            <span class="block w-1 h-1 rounded-full bg-gh-muted"></span>
        </div>
    </div>

    <main
        class="flex-1 min-w-0"
        :class="resizing && 'pointer-events-none'"
        style="contain: inline-size layout style; padding-bottom: var(--feedback-bar-h)"
    >
        {{ $slot }}
    </main>
</div>
