{{--
    Header affordance for the sidebar the resizable-sidebar-shell renders.

    Two buttons rather than one with a swapped icon: the chevron direction is
    the state read-out, so the visible glyph always points where the click
    sends the sidebar. Both mutate Alpine.store('settings').toggleSidebar(),
    the same entry point the hyper+S shortcut and the native View-menu item use.
--}}

@php
    $combo = \App\Support\Shortcuts::display('sidebar.toggle');
@endphp

{{-- Both chevrons occupy one grid cell, so the cell keeps its size no matter
     which is showing. Only the "show" chevron is cloaked: the default state is
     expanded, so "hide" is already correct before Alpine boots, and cloaking it
     too would empty the cell for a frame and shift the header. Collapsed, both
     paint stacked for that one frame — invisible, and the cell stays put. --}}
<div class="grid place-items-center">
    <flux:button variant="ghost" size="sm" icon="chevron-double-left" icon:variant="outline"
        tooltip="Hide sidebar · {{ $combo }}"
        aria-label="Hide sidebar ({{ $combo }})"
        data-testid="sidebar-hide"
        class="col-start-1 row-start-1"
        @click="$store.settings.toggleSidebar()"
        x-show="!$store.settings.sidebarCollapsed" />
    <flux:button variant="ghost" size="sm" icon="chevron-double-right" icon:variant="outline"
        tooltip="Show sidebar · {{ $combo }}"
        aria-label="Show sidebar ({{ $combo }})"
        data-testid="sidebar-show"
        class="col-start-1 row-start-1"
        @click="$store.settings.toggleSidebar()"
        x-show="$store.settings.sidebarCollapsed" x-cloak />
</div>
