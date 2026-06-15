@props(['keys'])

<span {{ $attributes }}>
    @foreach((array) $keys as $k)
        <kbd class="px-1 py-0.5 rounded border border-gh-border text-[10px]">{{ $k }}</kbd>
    @endforeach
    {{ $slot }}
</span>
