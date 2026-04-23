{{--
    Right-click menu for "Open on remote" + "Copy remote link" actions.

    Must be rendered inside an `x-data="contextMenu()"` scope whose trigger
    element dispatches `openAt($event)` from `@contextmenu.prevent`. The
    enclosing Livewire component must `use App\Concerns\InteractsWithRemoteLinks`.

    Static use (target known at render time):
      <x-remote-link-menu project-slug="..." type="repo" label="repository" />

    Dynamic use (target from Alpine state, e.g. in an x-for loop):
      <x-remote-link-menu
          project-slug="..."
          type-js="'branch'"
          params-js="{ name: branch.name.replace(/^origin\//, '') }"
          label-js="'branch ' + branch.name"
      />

    Teleports to <body> so fixed positioning isn't clipped by sticky /
    overflow-hidden ancestors (e.g. the diff-file header).
--}}

@props([
    'projectSlug',
    'type' => null,
    'typeJs' => null,
    'params' => [],
    'paramsJs' => null,
    'label' => 'on remote',
    'labelJs' => null,
])

@php
    // Flags ensure the JS literal is safe to paste into an HTML attribute
    // (`@click="..."`) — no raw quotes, tags, or ampersands.
    $attrJsonFlags = JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $typeExpr = $typeJs ?? json_encode((string) $type, $attrJsonFlags);
    $paramsExpr = $paramsJs ?? json_encode($params, $attrJsonFlags);
    $actions = [
        ['method' => 'openRemote', 'icon' => 'arrow-top-right-on-square', 'verb' => 'Open', 'suffix' => '', 'nativeOnly' => true],
        ['method' => 'copyRemoteLink', 'icon' => 'link', 'verb' => 'Copy', 'suffix' => ' link', 'nativeOnly' => false],
    ];
@endphp

@assets
<script src="/js/context-menu.js"></script>
@endassets

<template x-teleport="body">
    <div
        x-show="open"
        x-cloak
        x-transition.opacity.duration.75ms
        @click.outside="close()"
        @keydown.escape.window="close()"
        @click="close()"
        class="fixed z-[100] min-w-[180px] py-1 rounded-md border border-gh-border bg-gh-surface shadow-lg"
        :style="`left:${x}px; top:${y}px`"
    >
        @foreach($actions as $action)
            @if($action['nativeOnly'])@native @endif
                <button
                    type="button"
                    @click.stop="$wire.{{ $action['method'] }}(@js($projectSlug), {{ $typeExpr }}, {{ $paramsExpr }}); close()"
                    class="w-full text-left px-3 py-1.5 text-xs font-mono text-gh-text hover:bg-gh-border/40 flex items-center gap-2 cursor-pointer"
                >
                    <flux:icon icon="{{ $action['icon'] }}" variant="outline" class="!size-3.5 text-gh-muted" />
                    @if($labelJs !== null)
                        <span>{{ $action['verb'] }} </span><span x-text="{{ $labelJs }}"></span>@if($action['suffix'])<span>{{ $action['suffix'] }}</span>@endif
                    @else
                        <span>{{ $action['verb'] }} {{ $label }}{{ $action['suffix'] }}</span>
                    @endif
                </button>
            @if($action['nativeOnly'])@endnative @endif
        @endforeach
    </div>
</template>
