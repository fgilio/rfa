@props(['footerClearanceClass' => ''])

{{-- Width persists across pages via Alpine.store('settings').sidebarWidth. --}}

<div
    {{ $attributes->merge(['class' => 'flex']) }}
    x-data="{
        resizing: false,
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
    <aside
        class="shrink-0 sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] overflow-y-auto border-r border-gh-border bg-gh-bg hidden lg:block {{ $footerClearanceClass }}"
        :style="{ width: $store.settings.sidebarWidth + 'px' }"
        x-ref="sidebar"
    >
        {{ $sidebar }}
    </aside>

    <div
        data-testid="sidebar-resize-handle"
        role="separator"
        aria-orientation="vertical"
        aria-label="Resize sidebar"
        title="Drag to resize · double-click to reset"
        class="group/resize hidden lg:flex sticky top-[var(--header-h)] h-[calc(100vh-var(--header-h))] w-0 cursor-col-resize items-center justify-center z-10 shrink-0"
        style="padding: 0 6px; margin: 0 -6px;"
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
        class="flex-1 min-w-0 {{ $footerClearanceClass }}"
        :class="resizing && 'pointer-events-none'"
        style="contain: inline-size layout style"
    >
        {{ $slot }}
    </main>
</div>
